<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev 97 — PUBLIC LIVE DEMO (Ejaz: a guided demo with demo data, auto-reset
 * every 3 hours, "it helps reduce stress on our staff").
 *
 * Flow: landing menu "Live Demo" → GET /demo (small form: name/mobile/company
 * — every visitor becomes a LEAD) → POST /demo/start → auto-login as the demo
 * workspace's admin → /app?tour=1 (interactive overlay tour in the boot JS).
 *
 * The demo workspace is the shared `demo` subdomain tenant created by
 * `php artisan demo:reset` (scheduled every 3 hours in routes/console.php).
 * Outgoing email + WhatsApp from the demo tenant are MUTED (see isDemoTenant,
 * used by MailService + WaService) so visitors can never trigger real messages.
 */
class DemoAccessController extends Controller
{
    /** Cached per-request: is this tenant the public demo workspace? */
    private static $demoTid = false;   // false = not looked up yet; null = no demo tenant

    public static function demoTenantId(): ?int
    {
        if (self::$demoTid === false) {
            try {
                self::$demoTid = Schema::hasTable('tenants')
                    ? (DB::table('tenants')->where('subdomain', 'demo')->whereNull('deleted_at')->value('id') ?: null)
                    : null;
            } catch (\Throwable $e) {
                self::$demoTid = null;
            }
        }

        return self::$demoTid;
    }

    public static function isDemoTenant($tenantId): bool
    {
        if (! $tenantId) {
            return false;
        }
        $demo = self::demoTenantId();

        return $demo !== null && (int) $tenantId === (int) $demo;
    }

    /** GET /demo — the public entry page (small lead form). */
    public function show()
    {
        $ready = $this->demoUser() !== null;

        return view('demo-entry', ['ready' => $ready]);
    }

    /**
     * rev 104 — EDITION DEMONSTRATIONS (Ejaz: "/app1 for level-1, /app2 for
     * level-2, /app3 for level-3"). One-click, sales-team-driven entries into
     * the SAME shared demo workspace, viewed through that edition's licence:
     * the session carries edition_demo and Edition::current() honours it for
     * demo-tenant users only. No OTP here — these URLs are for demos YOUR
     * team conducts; the lead-capturing public demo stays at /demo.
     */
    public const EDITION_DEMOS = [
        '1' => ['l1', 'SmartPRS-L1', 'Core HR', 'The complete, compliant HR & payroll system — people, GPS + selfie attendance, leave, full statutory payroll (PF · ESI · PT · TDS), notices and reports.'],
        '2' => ['l2', 'SmartPRS-L2', 'Advanced', 'Everything in L1 plus the nine advanced modules — Recruitment & ATS, HR Letters, Compensation & Claims, Multi-Company, Performance, Learning, WhatsApp Suite, Analytics, Communication Plus.'],
        '3' => ['l3', 'SmartPRS-L3', 'Collections DNA', 'The full platform — everything in L2 plus Live Salary, the Incentive & Earnings Engine, Field Force & Compliance and the Volume Hiring Machine.'],
        // rev 105: the COMPLETE platform demo the team gives personally.
        'full' => [null, 'SmartPRS', 'Complete Platform', 'The entire platform with nothing held back — all sixteen modules, every screen, settings included. The personal demonstration experience for your prospect, driven by the Ametecs team.'],
    ];

    /** rev 105: team PIN — unlocks the unrestricted personal demos. */
    private static function teamPinOk(Request $request): bool
    {
        $pin = strtolower(trim((string) $request->input('pin', '')));
        $real = strtolower(trim((string) config('smartprs.team_pin', 'ametecs')));

        return $pin !== '' && hash_equals($real, $pin);
    }

    /** GET /app1 | /app2 | /app3 | /teamdemo — branded team entry page. */
    public function editionShow(string $n)
    {
        $d = self::EDITION_DEMOS[$n] ?? null;
        abort_unless($d, 404);

        return view('edition-demo-entry', [
            'n' => $n, 'edition' => $d[0], 'title' => $d[1], 'subtitle' => $d[2],
            'blurb' => $d[3], 'ready' => $this->demoUser() !== null,
            'action' => $n === 'full' ? url('/teamdemo/start') : url('/app'.$n.'/start'),
        ]);
    }

    /**
     * POST /app{n}/start | /teamdemo/start — TEAM demo login (rev 105):
     * PIN-gated, UNRESTRICTED (demo_team session flag switches off the demo
     * write-guard and the hidden-screens lockdown — the team shows everything
     * personally; the 3-hour reset cleans up afterwards). Edition demos also
     * carry the licence view; /teamdemo is the full platform.
     */
    public function editionStart(Request $request, string $n)
    {
        $d = self::EDITION_DEMOS[$n] ?? null;
        abort_unless($d, 404);
        $back = $n === 'full' ? '/teamdemo' : '/app'.$n;
        if (! self::teamPinOk($request)) {
            return redirect($back)->with('demo_err', 'Wrong team PIN — these personal-demo entries are for the Ametecs team. Visitors: please use the Live Demo at smartprs.com/demo.');
        }
        $u = $this->demoUser();
        if (! $u) {
            return redirect($back)->with('demo_err', 'The demo is being refreshed right now — please try again in a couple of minutes.');
        }
        Auth::loginUsingId($u->id);
        $request->session()->regenerate();
        if ($d[0]) {
            $request->session()->put('edition_demo', $d[0]);
        } else {
            $request->session()->forget('edition_demo');
        }
        $request->session()->put('demo_team', 1);

        return redirect($request->boolean('tour') ? '/app?tour=1' : '/app');
    }

    /** The demo workspace's admin login (created by demo:reset). */
    private function demoUser()
    {
        $tid = self::demoTenantId();
        if (! $tid) {
            return null;
        }

        return DB::table('users')->where('tenant_id', $tid)
            ->whereRaw('LOWER(email) = ?', ['demo-admin@smartprs.in'])
            ->where('status', 'active')->first()
            ?: DB::table('users')->where('tenant_id', $tid)->where('status', 'active')->orderBy('id')->first();
    }

    /**
     * rev 98: POST /demo/otp — verify the visitor is REAL before the demo
     * (Ejaz: "send OTP on WhatsApp so we can catch him, or email, both").
     * Sends a 6-digit code to WhatsApp (Interakt AUTHENTICATION template,
     * default name smartprs_otp) and to email when given. Fail-soft per
     * channel; in non-production the code is returned for testing.
     */
    public function otp(Request $request)
    {
        try {
            if (trim((string) $request->input('website', '')) !== '') {
                return response()->json(['ok' => true, 'channels' => ['whatsapp']]);
            }
            $v = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'mobile' => ['required', 'string', 'max:20'],
                'company' => ['nullable', 'string', 'max:160'],
                'email' => ['nullable', 'email', 'max:160'],
            ]);
            $digits = substr(preg_replace('/\D+/', '', $v['mobile']), -10);
            if (strlen($digits) < 10) {
                return response()->json(['ok' => false, 'error' => 'Please enter a valid 10-digit mobile number.'], 422);
            }

            $code = (string) random_int(100000, 999999);
            $request->session()->put('demo_otp', [
                'hash' => hash('sha256', $code.$digits),
                'mobile' => $digits,
                'exp' => now()->addMinutes(10)->timestamp,
                'tries' => 0,
            ]);

            $channels = [];
            try {
                if (\App\Services\WaService::sendTemplate([
                    'mobile' => $digits,
                    'template' => \App\Services\WaService::templateNameFor('otp'),
                    'kind' => 'demo.otp',
                    'bodyValues' => [$code],
                ])) {
                    $channels[] = 'WhatsApp';
                }
            } catch (\Throwable $e) {
            }
            try {
                if (! empty($v['email'])) {
                    $id = \App\Services\MailService::queue([
                        'tenant_id' => null, 'kind' => 'demo.otp',
                        'to' => $v['email'],
                        'subject' => $code.' is your SmartPRS demo code',
                        'heading' => 'Your SmartPRS verification code',
                        'intro' => 'Use this code to enter the SmartPRS live demo. It is valid for 10 minutes.',
                        'lines' => ['Verification code' => $code],
                    ]);
                    if ($id) {
                        $channels[] = 'email';
                    }
                }
            } catch (\Throwable $e) {
            }

            $resp = ['ok' => true, 'channels' => $channels,
                'message' => $channels
                    ? 'We sent a 6-digit code to your '.implode(' and ', $channels).'.'
                    : 'We could not reach you on WhatsApp/email right now — please retry, or write to sales@ametecsindia.com.'];
            // Local/dev convenience: show the code when not in production.
            if (config('app.env') !== 'production') {
                $resp['devOtp'] = $code;
            }

            return response()->json($resp);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /demo/start — verify the OTP, capture the VERIFIED lead, sign in. */
    public function start(Request $request)
    {
        try {
            // Honeypot (same trick as the landing form).
            if (trim((string) $request->input('website', '')) !== '') {
                return response()->json(['ok' => true, 'redirect' => url('/')]);
            }
            $v = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'mobile' => ['required', 'string', 'max:20'],
                'company' => ['nullable', 'string', 'max:160'],
                'email' => ['nullable', 'email', 'max:160'],
                'otp' => ['required', 'string', 'max:10'],
            ]);

            // rev 98: OTP check (session-bound, 10-minute expiry, 5 attempts).
            $digits = substr(preg_replace('/\D+/', '', $v['mobile']), -10);
            $sess = $request->session()->get('demo_otp');
            if (! $sess || ($sess['mobile'] ?? '') !== $digits) {
                return response()->json(['ok' => false, 'error' => 'Please request a fresh OTP for this mobile number.'], 422);
            }
            if (now()->timestamp > ($sess['exp'] ?? 0)) {
                $request->session()->forget('demo_otp');

                return response()->json(['ok' => false, 'error' => 'That code has expired — please request a fresh OTP.'], 422);
            }
            if (($sess['tries'] ?? 0) >= 5) {
                $request->session()->forget('demo_otp');

                return response()->json(['ok' => false, 'error' => 'Too many wrong attempts — please request a fresh OTP.'], 422);
            }
            if (! hash_equals($sess['hash'], hash('sha256', trim($v['otp']).$digits))) {
                $sess['tries'] = ($sess['tries'] ?? 0) + 1;
                $request->session()->put('demo_otp', $sess);

                return response()->json(['ok' => false, 'error' => 'That code is not correct — please check and try again.'], 422);
            }
            $request->session()->forget('demo_otp');

            $u = $this->demoUser();
            if (! $u) {
                return response()->json(['ok' => false, 'error' => 'The live demo is being refreshed right now — please try again in a couple of minutes.'], 422);
            }

            // Every demo visitor is a sales lead — mobile now VERIFIED (fail-soft).
            try {
                LeadController::recordLead([
                    'name' => $v['name'], 'mobile' => $digits,
                    'company' => $v['company'] ?? null, 'email' => $v['email'] ?? null,
                    'challenges' => 'Mobile verified by OTP (live demo)',
                ], 'live_demo');
            } catch (\Throwable $e) {
            }

            Auth::loginUsingId($u->id);
            $request->session()->regenerate();
            // rev 104: /demo always shows the FULL product — clear any edition
            // override left from an /app1 /app2 /app3 demonstration.
            // rev 105: and /demo is the PUBLIC demo — always restricted.
            $request->session()->forget('edition_demo');
            $request->session()->forget('demo_team');

            return response()->json(['ok' => true, 'redirect' => url('/app?tour=1')]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => implode(' ', collect($e->errors())->flatten()->all())], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
