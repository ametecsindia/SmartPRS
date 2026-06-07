<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function show(Request $request)
    {
        // rev 109: a request arriving on a tenant's CUSTOM DOMAIN (their CNAME
        // pointing at this server) gets THEIR branded login automatically.
        $t = self::tenantByHost($request->getHost());
        if ($t) {
            return $this->brandedForTenant($t);
        }

        return view('login');
    }

    /** rev 109: tenant whose custom_domain equals the request host (per-request cache). */
    public static function tenantByHost(?string $host): ?object
    {
        static $cache = [];
        $host = strtolower(trim((string) $host));
        if ($host === '' || str_starts_with($host, 'localhost') || str_starts_with($host, '127.')) {
            return null;
        }
        if (array_key_exists($host, $cache)) {
            return $cache[$host];
        }
        try {
            if (! Schema::hasColumn('tenants', 'custom_domain')) {
                return $cache[$host] = null;
            }

            return $cache[$host] = DB::table('tenants')->whereNull('deleted_at')
                ->whereRaw('LOWER(custom_domain) = ?', [$host])->first();
        } catch (\Throwable $e) {
            return $cache[$host] = null;
        }
    }

    /** Branded login for a tenant resolved by custom domain (master company brand). */
    private function brandedForTenant(object $t)
    {
        try {
            $q = DB::table('companies')->where('tenant_id', $t->id)->whereNull('deleted_at');
            try {
                $company = (clone $q)->orderByDesc('is_master')->orderBy('id')->first();
            } catch (\Throwable $e) {
                $company = $q->orderBy('id')->first();
            }
            if ($company) {
                session(['portal_company_id' => $company->id]);

                return view('login', [
                    'portalCompany' => $company->name,
                    'portalColor' => $company->color ?? null,
                    'portalLogo' => $company->logo_path ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // fall through to the standard login
        }

        return view('login');
    }

    /**
     * Dedicated platform Super Admin login portal: /super
     * Same form, but themed as the platform entry and — on submit — only a
     * super_admin may sign in through it (others are bounced with a message).
     */
    public function showSuper()
    {
        return view('login', ['superMode' => true]);
    }

    /**
     * Branded per-company login portal: /c/{slug}. Resolves the company by a
     * slugified name, remembers it in the session (so the post-login workspace
     * can theme to that company) and shows the normal login with its brand.
     * Fail-soft: an unknown slug just falls back to the standard login.
     */
    public function showBranded(string $slug)
    {
        try {
            $company = DB::table('companies')->whereNull('deleted_at')->get(['id', 'name', 'color', 'logo_path'])
                ->first(fn ($c) => Str::slug($c->name) === Str::slug($slug));
            if ($company) {
                session(['portal_company_id' => $company->id]);

                return view('login', [
                    'portalCompany' => $company->name,
                    'portalColor' => $company->color ?? null,
                    'portalLogo' => $company->logo_path ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // fall through to the standard login
        }

        return view('login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Block disabled accounts before authenticating (admin/HR can disable a
        // login from User Management). Add status=active to the attempt so a
        // disabled user can never get a session, even with the right password.
        if (Auth::attempt($credentials + ['status' => 'active'], $request->boolean('remember'))) {
            // If this came from the /super portal, only super_admin may pass.
            if ($request->boolean('super') && ! $request->user()?->hasRole('super_admin')) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors(['email' => 'This portal is for platform Super Admins only.'])->onlyInput('email');
            }
            // Subscription lock-out (rev 75): when a tenant is past its 7-day
            // grace, only the tenant ADMIN may sign in (they land on My
            // Subscription to renew). Everyone else gets a clear message.
            try {
                $u = $request->user();
                if ($u && $u->tenant_id
                    && \App\Services\SubscriptionService::state((int) $u->tenant_id)['state'] === 'locked'
                    && ! $u->hasRole('admin')) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return back()->withErrors(['email' => 'This workspace is currently suspended (subscription expired). Please contact your administrator to renew.'])->onlyInput('email');
                }
            } catch (\Throwable $e) {
                // fail-soft: never block login on an internal billing error
            }
            $request->session()->regenerate();

            return redirect()->intended(route('app'));
        }

        // Distinguish a disabled account from wrong credentials, for a clearer message.
        $u = \Illuminate\Support\Facades\DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower(trim($credentials['email']))])->first();
        if ($u && ($u->status ?? 'active') === 'disabled') {
            return back()->withErrors(['email' => 'This account has been disabled. Please contact your administrator.'])->onlyInput('email');
        }

        return back()
            ->withErrors(['email' => 'Those credentials do not match our records.'])
            ->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // ============================================================ password ===
    // Self-creating token table so this works on the deployed schema without a
    // manual migration (the project has no password_reset_tokens migration).

    private static function ensureResetTable(): void
    {
        if (Schema::hasTable('user_password_resets')) {
            return;
        }
        Schema::create('user_password_resets', function (Blueprint $t) {
            $t->id();
            $t->string('email')->index();
            $t->string('token', 64)->index();   // stored hashed
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
    }

    /** Forgot-password form. */
    public function showForgot()
    {
        return view('auth.forgot-password');
    }

    /**
     * Email a reset link. Always reports success (no account enumeration). Light
     * throttle: skip if a still-valid unused token was issued in the last 2 min.
     */
    public function sendReset(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($request->input('email')));

        try {
            self::ensureResetTable();
            $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                $recent = DB::table('user_password_resets')
                    ->where('email', $email)->whereNull('used_at')
                    ->where('created_at', '>=', now()->subMinutes(2))->exists();
                if (! $recent) {
                    $token = Str::random(48);
                    DB::table('user_password_resets')->insert([
                        'email' => $email,
                        'token' => hash('sha256', $token),
                        'expires_at' => now()->addHour(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $link = url('/reset-password/'.$token.'?email='.urlencode($email));
                    MailService::queue([
                        'tenant_id' => $user->tenant_id ?? null,
                        'company_id' => $user->company_id ?? null,
                        'to' => $user->email,
                        'to_name' => $user->name ?? '',
                        'subject' => 'Reset your SmartPRS password',
                        'heading' => 'Password reset requested',
                        'intro' => 'We received a request to reset your SmartPRS password. This link is valid for 1 hour.',
                        'body' => 'If you did not request this, you can safely ignore this email — your password will not change.',
                        'cta_label' => 'Reset password',
                        'cta_url' => $link,
                        'kind' => 'auth.reset',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // never reveal internal errors on the public form
        }

        return back()->with('status', 'If that email is registered, a reset link is on its way. Please check your inbox.');
    }

    /** Reset form (from the emailed link). */
    public function showReset(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /** Apply the new password if the token is valid and unexpired. */
    public function doReset(Request $request)
    {
        $v = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $email = strtolower(trim($v['email']));

        try {
            self::ensureResetTable();
            $row = DB::table('user_password_resets')
                ->where('email', $email)
                ->where('token', hash('sha256', $v['token']))
                ->whereNull('used_at')
                ->where('expires_at', '>=', now())
                ->orderByDesc('id')->first();

            if (! $row) {
                return back()->withErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.'])->onlyInput('email');
            }

            $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $user) {
                return back()->withErrors(['email' => 'Account not found.'])->onlyInput('email');
            }

            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($v['password']),
                'updated_at' => now(),
            ]);
            DB::table('user_password_resets')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);
            // Invalidate any other outstanding tokens for this email.
            DB::table('user_password_resets')->where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);

            return redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.');
        } catch (\Throwable $e) {
            return back()->withErrors(['email' => 'Could not reset password. Please try again.'])->onlyInput('email');
        }
    }

    /** Change password while logged in (verifies the current password). */
    public function changePassword(Request $request)
    {
        try {
            $v = $request->validate([
                'current' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
            $user = $request->user();
            if (! Hash::check($v['current'], $user->password)) {
                return response()->json(['ok' => false, 'error' => 'Your current password is incorrect.'], 422);
            }
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($v['password']),
                'updated_at' => now(),
            ]);

            return response()->json(['ok' => true, 'message' => 'Password changed.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
