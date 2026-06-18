<?php

namespace App\Http\Controllers;

use App\Services\Edition;
use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * rev 107 — ON-PREM CLIENT SIDE: activation + Administration → Updates.
 *
 * Local licence state lives in the settings table (tenant_id 0, key
 * 'licence'): {key_enc, cert, activated_at, last_check, last_ok}.
 * The update server URL is config('smartprs.update_url') — baked default
 * https://smartprs.com/update (SRS FR-5.1).
 *
 * Apply (FR-9.2): download → sha256 verify → backup code folders →
 * extract (never .env / storage / vendor) → migrate → clear caches.
 * Any failure → restore the backup taken in the same run.
 */
class ClientUpdateController extends Controller
{
    private const CODE_DIRS = ['app', 'config', 'database', 'resources', 'routes'];

    // ---------- local licence state ----------

    public static function state(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }
            $raw = DB::table('settings')->where('tenant_id', 0)->where('key', 'licence')->value('value');

            return $raw ? (json_decode($raw, true) ?: []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function saveState(array $st): void
    {
        try {
            $row = ['tenant_id' => 0, 'key' => 'licence'];
            $vals = ['value' => json_encode($st), 'updated_at' => now()];
            if (DB::table('settings')->where($row)->exists()) {
                DB::table('settings')->where($row)->update($vals);
            } else {
                DB::table('settings')->insert($row + $vals + ['created_at' => now()]);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function activated(): bool
    {
        $st = self::state();

        return ! empty($st['cert']) && ! empty($st['activated_at']);
    }

    /**
     * rev139 — expiry-aware licence state for the LOGIN gate.
     * Returns ['state' => 'none'|'expired'|'ok', 'expires_on' => ?string,
     * 'company' => ?string]. Uses the LOCALLY stored certificate (offline
     * grace): once activated the install keeps running offline until the
     * stored amc_expires_on passes — the online check only happens when a
     * code is entered or via the periodic heartbeat.
     */
    public static function licenceStatus(): array
    {
        $st = self::state();
        $cert = $st['cert'] ?? [];
        $company = $st['company'] ?? null;
        if (empty($cert) || empty($st['activated_at'])) {
            return ['state' => 'none', 'expires_on' => null, 'company' => $company];
        }
        $exp = $cert['amc_expires_on'] ?? null;
        if ($exp && $exp < now()->toDateString()) {
            return ['state' => 'expired', 'expires_on' => $exp, 'company' => $company];
        }

        return ['state' => 'ok', 'expires_on' => $exp, 'company' => $company];
    }

    /**
     * True when access should be allowed. ALWAYS true off the on-prem
     * editions (or when enforcement is off) so SaaS / Super Admin login is
     * never touched. On an enforced on-prem install it is true only while a
     * stored, unexpired certificate exists.
     */
    public static function licenceValid(): bool
    {
        if (! Edition::isOnPrem()
            || ! filter_var(config('smartprs.licence_enforce', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return self::licenceStatus()['state'] === 'ok';
    }

    /**
     * rev139 — ONLINE validate a License Code against the licence server and,
     * on success, store the signed certificate locally. Shared by the
     * Activation screen and the login-form LC field. Returns
     * ['ok' => bool, 'error' => ?string, 'company' => ?string].
     */
    public static function activateKey(string $key): array
    {
        if (strlen(LicenseService::normalize($key)) < 16) {
            return ['ok' => false, 'error' => 'That does not look like a SmartPRS License Code — please check and try again.'];
        }
        try {
            $resp = Http::timeout(20)->post(config('smartprs.update_url').'/activate', [
                'key' => $key,
                'fingerprint' => self::fingerprint(),
                'server_name' => gethostname() ?: 'unknown',
                'edition' => Edition::current(),
            ]);
            $j = $resp->json();
            if (! is_array($j) || empty($j['ok'])) {
                return ['ok' => false, 'error' => is_array($j) && ! empty($j['error']) ? $j['error'] : 'Could not reach the licence server — check the internet connection and try again.'];
            }
            // Subscription/block model: never accept an already-expired code.
            $exp = $j['cert']['amc_expires_on'] ?? ($j['amc_expires_on'] ?? null);
            if ($exp && $exp < now()->toDateString()) {
                return ['ok' => false, 'error' => 'That License Code expired on '.$exp.'. Please obtain a current code from Ametecs (WhatsApp 9000098877).'];
            }
            self::saveState([
                'key_enc' => Crypt::encryptString(LicenseService::normalize($key)),
                'cert' => $j['cert'] ?? [],
                'company' => $j['company'] ?? '',
                'activated_at' => now()->toDateTimeString(),
                'last_ok' => now()->toDateTimeString(),
            ]);

            return ['ok' => true, 'error' => null, 'company' => $j['company'] ?? ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the licence server — check the internet connection and try again.'];
        }
    }

    /** Stable installation fingerprint (server identity, not hardware-fragile). */
    public static function fingerprint(): string
    {
        return hash('sha256', (gethostname() ?: 'host').'|'.Edition::current().'|'.base_path());
    }

    private function guard(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->hasRole('admin') || $u->hasRole('super_admin')), 403, 'Admin only.');
    }

    // ---------- activation ----------

    /** GET /app/activate — the branded activation screen. */
    public function activateShow(Request $request)
    {
        $this->guard($request);

        return view('licence-activate', [
            'edition' => Edition::label(),
            'activated' => self::activated(),
            'state' => self::state(),
        ]);
    }

    /** POST /app/activate {key} */
    public function activatePost(Request $request)
    {
        $this->guard($request);
        $res = self::activateKey(trim((string) $request->input('key', '')));
        if (empty($res['ok'])) {
            return back()->with('lic_err', $res['error'] ?? 'Could not activate — please try again.');
        }

        return redirect('/app')->with('lic_ok', 'SmartPRS is activated. Welcome aboard!');
    }

    // ---------- Administration → Updates ----------

    private function key(): ?string
    {
        $st = self::state();
        try {
            return ! empty($st['key_enc']) ? Crypt::decryptString($st['key_enc']) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** GET /app/updates/status — everything the screen shows. */
    public function status(Request $request)
    {
        $this->guard($request);
        $st = self::state();
        $hist = [];
        try {
            if (Schema::hasTable('client_updates')) {
                $hist = DB::table('client_updates')->orderByDesc('id')->limit(20)->get();
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'ok' => true,
            'version' => config('smartprs.version'),
            'edition' => Edition::label(),
            'activated' => self::activated(),
            'company' => $st['company'] ?? '',
            'amc_expires_on' => $st['cert']['amc_expires_on'] ?? null,
            'last_check' => $st['last_check'] ?? null,
            'pending' => $st['pending'] ?? null,           // last offered update, if any
            'history' => $hist,
        ]);
    }

    /** POST /app/updates/check — ask the platform. */
    public function check(Request $request)
    {
        $this->guard($request);
        $key = $this->key();
        if (! $key) {
            return response()->json(['ok' => false, 'error' => 'Not activated yet — activate the licence first.'], 422);
        }
        try {
            $resp = Http::timeout(20)->post(config('smartprs.update_url').'/check', [
                'key' => $key, 'version' => config('smartprs.version'),
            ]);
            $j = $resp->json();
            if (! is_array($j)) {
                return response()->json(['ok' => false, 'error' => 'The update server did not answer — try again later.'], 422);
            }
            $st = self::state();
            $st['last_check'] = now()->toDateTimeString();
            if (! empty($j['ok'])) {
                $st['last_ok'] = now()->toDateTimeString();
                $st['pending'] = $j['update'] ?? null;
                if (isset($j['amc_expires_on'])) {
                    $st['cert']['amc_expires_on'] = $j['amc_expires_on'];
                }
            }
            self::saveState($st);
            $this->logLocal('check', ! empty($j['update']) ? ('offered '.$j['update']['version']) : (($j['reason'] ?? '')));

            return response()->json($j);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not reach the update server — check the internet connection.'], 422);
        }
    }

    /** POST /app/updates/apply {version} — download, backup, apply, migrate. */
    public function apply(Request $request)
    {
        $this->guard($request);
        @set_time_limit(600);
        $key = $this->key();
        $version = trim((string) $request->input('version', ''));
        $st = self::state();
        $pending = $st['pending'] ?? null;
        if (! $key || ! $pending || $pending['version'] !== $version) {
            return response()->json(['ok' => false, 'error' => 'Please run "Check for updates" first.'], 422);
        }

        $workDir = storage_path('app/updates');
        @mkdir($workDir, 0775, true);
        $zipPath = $workDir.'/SmartPRS-Update-'.$version.'.zip';

        // 1) Download.
        try {
            $resp = Http::timeout(300)->withOptions(['sink' => $zipPath])
                ->get(config('smartprs.update_url').'/download/'.$version, ['key' => $key]);
            if (! $resp->ok() || ! is_file($zipPath) || filesize($zipPath) < 1000) {
                @unlink($zipPath);

                return response()->json(['ok' => false, 'error' => 'Download failed — the update may not be granted to this licence.'], 422);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Download failed: '.$e->getMessage()], 422);
        }

        // 2) Verify checksum (FR-9.2).
        if (! empty($pending['checksum']) && ! hash_equals($pending['checksum'], hash_file('sha256', $zipPath))) {
            @unlink($zipPath);
            $this->logLocal('failed', $version.': checksum mismatch');

            return response()->json(['ok' => false, 'error' => 'The downloaded file failed its integrity check — update aborted, nothing was changed. Please try again.'], 422);
        }

        // 3) Backup the code folders (rollback point).
        $bk = storage_path('app/backups/'.$version.'-'.date('YmdHis'));
        try {
            foreach (self::CODE_DIRS as $d) {
                $this->copyDir(base_path($d), $bk.'/'.$d);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not take the safety backup — update aborted, nothing was changed.'], 422);
        }

        // 4) Extract over the code (never .env, storage, vendor).
        try {
            $zip = new \ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Bad zip');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $clean = ltrim(str_replace('\\', '/', $name), '/');
                if ($clean === '' || str_contains($clean, '..')) {
                    continue;
                }
                $top = explode('/', $clean)[0];
                if (in_array($top, ['.env', 'storage', 'vendor', 'node_modules'], true)) {
                    continue;
                }
                $dest = base_path($clean);
                if (str_ends_with($clean, '/')) {
                    @mkdir($dest, 0775, true);
                    continue;
                }
                @mkdir(dirname($dest), 0775, true);
                copy('zip://'.$zipPath.'#'.$name, $dest);
            }
            $zip->close();
        } catch (\Throwable $e) {
            $this->restore($bk);
            $this->logLocal('failed', $version.': extract failed, rolled back');

            return response()->json(['ok' => false, 'error' => 'Applying files failed — the previous version was RESTORED automatically. Please contact Ametecs (9000098877).'], 422);
        }

        // 5) Migrate + clear caches.
        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
        } catch (\Throwable $e) {
            $this->restore($bk);
            $this->logLocal('failed', $version.': migrate failed, rolled back');

            return response()->json(['ok' => false, 'error' => 'Database upgrade failed — the previous version was RESTORED automatically. Please contact Ametecs (9000098877).'], 422);
        }

        unset($st['pending']);
        $st['last_applied'] = $version.' on '.now()->toDateTimeString();
        self::saveState($st);
        $this->logLocal('applied', $version);
        @unlink($zipPath);

        return response()->json(['ok' => true, 'message' => 'Updated to '.$version.' successfully. A backup of the previous version is kept in storage/backups.']);
    }

    private function copyDir(string $src, string $dst): void
    {
        if (! is_dir($src)) {
            return;
        }
        @mkdir($dst, 0775, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $dst.'/'.$it->getSubPathname();
            if ($item->isDir()) {
                @mkdir($target, 0775, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function restore(string $bk): void
    {
        try {
            foreach (self::CODE_DIRS as $d) {
                if (is_dir($bk.'/'.$d)) {
                    $this->copyDir($bk.'/'.$d, base_path($d));
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function logLocal(string $action, string $detail): void
    {
        try {
            LicenseService::ensureTables();
            DB::table('client_updates')->insert([
                'client_id' => null, 'licence_id' => null,
                'version' => config('smartprs.version'), 'action' => $action,
                'detail' => mb_substr($detail, 0, 2000),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
