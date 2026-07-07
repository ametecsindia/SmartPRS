<?php

namespace App\Http\Controllers;

use App\Services\ETimeOfficeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rev157 — frontend "Biometric Device Setup" screen backend.
 * Stores the cloud-attendance API connection (eTimeOffice) per tenant in
 * biometric_configs and exposes load / save / test / sync to the SPA. Admin/HR
 * only. The password is never returned to the browser (only a hasPassword flag).
 */
class BiometricConfigController extends Controller
{
    private static function tid(Request $request): ?int
    {
        return $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
    }

    private static function ensureTable(): void
    {
        if (! Schema::hasTable('biometric_configs')) {
            Schema::create('biometric_configs', function ($t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('provider', 40)->default('etimeoffice');
                $t->boolean('enabled')->default(false);
                $t->string('base_url')->nullable();
                $t->string('endpoint')->nullable();
                $t->string('corp_id')->nullable();
                $t->string('username')->nullable();
                $t->text('password')->nullable();
                $t->string('empcode')->default('ALL');
                $t->string('emp_prefix', 20)->nullable();
                $t->timestamp('last_sync_at')->nullable();
                $t->string('last_status')->nullable();
                $t->integer('last_count')->default(0);
                $t->timestamps();
            });
        }
        // rev173e — In/Out Machine IDs: sites with a separate entry device and
        // exit device. A punch from the In machine = IN, from the Out machine =
        // OUT — overriding the feed's (often blank/wrong) INOUT flag.
        foreach (['in_machine_id', 'out_machine_id'] as $c) {
            if (! Schema::hasColumn('biometric_configs', $c)) {
                try {
                    Schema::table('biometric_configs', function ($t) use ($c) {
                        $t->string($c, 40)->nullable();
                    });
                } catch (\Throwable $e) {
                    // best-effort; save() will surface a real failure
                }
            }
        }
    }

    private function row(Request $request)
    {
        self::ensureTable();
        $tid = self::tid($request);
        $q = DB::table('biometric_configs');
        $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');

        return $q->orderByDesc('id')->first();
    }

    /** GET /app/biometric-config */
    public function show(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $r = $this->row($request);
        // rev169 — clients ship BLANK: return only SAVED values (empty when no
        // config exists yet). Defaults appear as placeholder hints in the UI,
        // never as pre-filled values, and no Ametecs data is ever baked in.
        $config = [
            'provider' => $r?->provider ?? '',
            'enabled' => (bool) ($r?->enabled ?? false),
            'base_url' => $r?->base_url ?? '',
            'endpoint' => $r?->endpoint ?? '',
            'corp_id' => $r?->corp_id ?? '',
            'username' => $r?->username ?? '',
            'empcode' => $r?->empcode ?? '',
            'emp_prefix' => $r?->emp_prefix ?? '',
            'in_machine_id' => $r?->in_machine_id ?? '',    // rev173e
            'out_machine_id' => $r?->out_machine_id ?? '',  // rev173e
        ];

        return response()->json([
            'ok' => true,
            'config' => $config,
            'hasPassword' => ! empty($r?->password),
            'lastSyncAt' => $r?->last_sync_at ?? null,
            'lastStatus' => $r?->last_status ?? null,
        ]);
    }

    /** POST /app/biometric-config — save (password only updated when provided). */
    public function save(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        self::ensureTable();
        $tid = self::tid($request);
        $r = $this->row($request);

        $data = [
            'tenant_id' => $tid,
            'provider' => trim((string) $request->input('provider', 'etimeoffice')) ?: 'etimeoffice',
            'enabled' => $request->boolean('enabled'),
            'base_url' => trim((string) $request->input('base_url', '')) ?: 'https://api.etimeoffice.com/api',
            'endpoint' => trim((string) $request->input('endpoint', '')) ?: 'DownloadPunchDataMCID',
            'corp_id' => trim((string) $request->input('corp_id', '')) ?: null,
            'username' => trim((string) $request->input('username', '')) ?: null,
            'empcode' => trim((string) $request->input('empcode', '')) ?: 'ALL',
            'emp_prefix' => trim((string) $request->input('emp_prefix', '')) ?: null,
            'in_machine_id' => trim((string) $request->input('in_machine_id', '')) ?: null,    // rev173e
            'out_machine_id' => trim((string) $request->input('out_machine_id', '')) ?: null,  // rev173e
            'updated_at' => now(),
        ];
        $pwd = (string) $request->input('password', '');
        if ($pwd !== '') {
            $data['password'] = Crypt::encryptString($pwd);
        }

        if ($r) {
            DB::table('biometric_configs')->where('id', $r->id)->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('biometric_configs')->insert($data);
        }

        return response()->json(['ok' => true]);
    }

    /** Build an effective config from posted values, falling back to the saved password. */
    private function effectiveConfig(Request $request): array
    {
        $r = $this->row($request);
        $savedPwd = null;
        if ($r && ! empty($r->password)) {
            try {
                $savedPwd = Crypt::decryptString($r->password);
            } catch (\Throwable $e) {
                $savedPwd = $r->password;
            }
        }
        $posted = (string) $request->input('password', '');

        return [
            'provider' => $request->input('provider', $r->provider ?? 'etimeoffice'),
            'enabled' => $request->boolean('enabled'),
            'base_url' => trim((string) $request->input('base_url', $r->base_url ?? '')) ?: 'https://api.etimeoffice.com/api',
            'endpoint' => trim((string) $request->input('endpoint', $r->endpoint ?? '')) ?: 'DownloadPunchDataMCID',
            'corp_id' => trim((string) $request->input('corp_id', $r->corp_id ?? '')),
            'username' => trim((string) $request->input('username', $r->username ?? '')),
            'password' => $posted !== '' ? $posted : $savedPwd,
            'empcode' => trim((string) $request->input('empcode', $r->empcode ?? 'ALL')) ?: 'ALL',
            'emp_prefix' => trim((string) $request->input('emp_prefix', $r->emp_prefix ?? '')),
            'in_machine_id' => trim((string) $request->input('in_machine_id', $r->in_machine_id ?? '')),    // rev173e
            'out_machine_id' => trim((string) $request->input('out_machine_id', $r->out_machine_id ?? '')),  // rev173e
            'tenant_id' => self::tid($request),
        ];
    }

    /** POST /app/biometric-config/test — fetch + parse, write nothing. */
    public function test(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $cfg = $this->effectiveConfig($request);
        if (! ETimeOfficeService::configured($cfg)) {
            return response()->json(['ok' => false, 'error' => 'Enter Corporate ID, Username and Password first.'], 422);
        }
        $to = now();
        $from = (clone $to)->subDay();
        $res = ETimeOfficeService::fetch($cfg, $from, $to);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
        }
        $parsed = ETimeOfficeService::parse($res['json'], $cfg);
        $lines = [];
        foreach (array_slice($parsed, 0, 8) as $p) {
            $lines[] = $p['emp_code'].'  '.$p['punch_at']->format('Y-m-d H:i').'  '.$p['direction']
                .(($p['machine'] ?? '') !== '' ? '  MC:'.$p['machine'] : '')
                .'  '.($p['name'] ?? '');
        }
        $preview = $parsed ? implode("\n", $lines) : substr($res['body'], 0, 1500);

        return response()->json(['ok' => true, 'parsed' => count($parsed), 'preview' => $preview]);
    }

    /** POST /app/biometric-config/sync — fetch + parse + import now. */
    public function sync(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $cfg = $this->effectiveConfig($request);
        if (! ETimeOfficeService::configured($cfg)) {
            return response()->json(['ok' => false, 'error' => 'Save the connection details first.'], 422);
        }
        $days = max(1, min(31, (int) $request->input('days', 1)));
        $to = now();
        $from = (clone $to)->subDays($days);
        $res = ETimeOfficeService::fetch($cfg, $from, $to);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => 'Connection failed: '.($res['error'] ?? 'unknown')], 422);
        }
        $punches = ETimeOfficeService::parse($res['json'], $cfg);
        $r = ETimeOfficeService::import($punches, $cfg);

        $status = 'Imported '.$r['imported'].' punch(es) for '.$r['matched'].' row(s)';
        $row = $this->row($request);
        if ($row) {
            DB::table('biometric_configs')->where('id', $row->id)->update([
                'last_sync_at' => now(), 'last_status' => $status, 'last_count' => $r['imported'], 'updated_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'imported' => $r['imported'],
            'matched' => $r['matched'],
            'unmatched' => count($r['unmatched']),
            'unmatchedCodes' => array_slice(array_keys($r['unmatched']), 0, 15),
        ]);
    }
}
