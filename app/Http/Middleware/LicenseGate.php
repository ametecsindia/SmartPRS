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
            if (! Edition::isOnPrem()
                || ! app()->environment('production')
                || ! filter_var(config('smartprs.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)
                || ClientUpdateController::activated()) {
                return $next($request);
            }

            $path = trim($request->path(), '/');
            foreach (self::ALLOW as $a) {
                if ($path === $a || str_starts_with($path, $a.'/')) {
                    return $next($request);
                }
            }

            $u = $request->user();
            $isAdmin = false;
            try {
                $isAdmin = $u && ($u->hasRole('admin') || $u->hasRole('super_admin'));
            } catch (\Throwable $e) {
            }

            if ($isAdmin) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'error' => 'SmartPRS is waiting for licence activation — open Activation and enter your key.'], 403);
                }

                return redirect('/app/activate');
            }

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => 'SmartPRS is not activated yet. Please ask your administrator.'], 403);
            }

            return response('SmartPRS is not activated yet. Please ask your administrator to enter the licence key.', 403);
        } catch (\Throwable $e) {
            return $next($request);
        }
    }
}
