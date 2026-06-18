<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ClientUpdateController;
use App\Services\Edition;
use Closure;
use Illuminate\Http\Request;

/**
 * rev 107 — ACTIVATION GATE (SRS FR-7; Ejaz: "when entered allows to enter
 * else it will stay at admin login... wait for activation").
 *
 * On an UNACTIVATED on-prem PRODUCTION installation: login works, but the
 * only destination is the activation screen (admins) or a friendly hold
 * message (everyone else). Your own demo/edition installs are exempt:
 * the gate runs only when APP_ENV=production AND licence_enforce is on.
 * Fail-soft: any internal error lets the request through — a licence
 * check must never take a client's HR system down.
 */
class LicenseGate
{
    private const ALLOW = ['app/activate', 'logout', 'app/change-password'];

    public function handle(Request $request, Closure $next)
    {
        try {
            // rev139: licenceValid() is expiry-aware — it is false both when
            // the install was never activated AND when a previously valid
            // licence has expired (subscription / block model). It always
            // returns true off the on-prem editions, so SaaS is untouched.
            if (! Edition::isOnPrem()
                || ! app()->environment('production')
                || ! filter_var(config('smartprs.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)
                || ClientUpdateController::licenceValid()) {
                return $next($request);
            }

            $path = trim($request->path(), '/');
            foreach (self::ALLOW as $a) {
                if ($path === $a || str_starts_with($path, $a.'/')) {
                    return $next($request);
                }
            }

            // Licence missing or expired → block and send the user back to the
            // LOGIN screen, where the License Code field (admins) or the hold
            // message (everyone else) is shown. The active session is ended so
            // re-entry of a valid LC happens during sign-in, per the spec.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => 'Your SmartPRS licence needs activation. Please sign in again and enter the License Code.'], 403);
            }
            try {
                \Illuminate\Support\Facades\Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } catch (\Throwable $e) {
            }

            return redirect('/login');
        } catch (\Throwable $e) {
            return $next($request);
        }
    }
}
