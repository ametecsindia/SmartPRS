<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * eTimeOffice (api.etimeoffice.com) cloud-attendance connector.
 *
 * Config is a plain array (provider, base_url, endpoint, corp_id, username,
 * password, empcode, emp_prefix). It comes from the per-tenant biometric_configs
 * row (frontend "Biometric Device Setup" screen) or, as a fallback, from .env.
 *
 * Auth: HTTP Basic where the username is "CorpID:User:Password:true" and the
 * password is the password. Punches are pulled for a window via DownloadPunchData*
 * and written into attendance_logs, matched to employees by emp_code AFTER the
 * emp_prefix is applied (device "12345" + prefix "A" -> "A12345").
 */
class ETimeOfficeService
{
    /** Config from .env (config/smartprs.php). */
    public static function envConfig(): array
    {
        $c = (array) config('smartprs.etimeoffice');

        return [
            'provider' => 'etimeoffice',
            'enabled' => (bool) ($c['enabled'] ?? false),
            'base_url' => $c['base_url'] ?? 'https://api.etimeoffice.com/api',
            'endpoint' => $c['endpoint'] ?? 'DownloadPunchDataMCID',
            'corp_id' => $c['corp_id'] ?? null,
            'username' => $c['username'] ?? null,
            'password' => $c['password'] ?? null,
            'empcode' => $c['empcode'] ?? 'ALL',
            'emp_prefix' => $c['emp_prefix'] ?? '',
            'tenant_id' => null,
        ];
    }

    /** Decode a biometric_configs DB row into a config array (password decrypted). */
    public static function rowToCfg(object $r): array
    {
        $pwd = null;
        if (! empty($r->password)) {
            try {
                $pwd = Crypt::decryptString($r->password);
            } catch (\Throwable $e) {
                $pwd = $r->password; // tolerate a plain value
            }
        }

        return [
            'provider' => $r->provider ?? 'etimeoffice',
            'enabled' => (bool) ($r->enabled ?? false),
            'base_url' => $r->base_url ?: 'https://api.etimeoffice.com/api',
            'endpoint' => $r->endpoint ?: 'DownloadPunchDataMCID',
            'corp_id' => $r->corp_id,
            'username' => $r->username,
            'password' => $pwd,
            'empcode' => $r->empcode ?: 'ALL',
            'emp_prefix' => $r->emp_prefix ?? '',
            'tenant_id' => $r->tenant_id ?? null,
        ];
    }

    /** Resolve config for one tenant: DB row first, else .env. */
    public static function configForTenant(?int $tid): ?array
    {
        if (Schema::hasTable('biometric_configs')) {
            $q = DB::table('biometric_configs');
            $tid ? $q->where('tenant_id', $tid) : $q->whereNull('tenant_id');
            $row = $q->orderByDesc('id')->first();
            if ($row) {
                return self::rowToCfg($row);
            }
        }
        $env = self::envConfig();

        return self::configured($env) ? $env : null;
    }

    /** Every enabled config to sync (all tenant rows, else .env). */
    public static function allConfigs(): array
    {
        $out = [];
        if (Schema::hasTable('biometric_configs')) {
            foreach (DB::table('biometric_configs')->where('enabled', true)->get() as $r) {
                $cfg = self::rowToCfg($r);
                if (self::configured($cfg)) {
                    $out[] = $cfg;
                }
            }
        }
        if (! $out) {
            $env = self::envConfig();
            if (($env['enabled'] ?? false) && self::configured($env)) {
                $out[] = $env;
            }
        }

        return $out;
    }

    public static function configured(array $cfg): bool
    {
        return ! empty($cfg['corp_id']) && ! empty($cfg['username']) && ! empty($cfg['password']);
    }

    private static function authPair(array $cfg): array
    {
        $user = $cfg['corp_id'].':'.$cfg['username'].':'.$cfg['password'].':true';

        return [$user, (string) $cfg['password']];
    }

    /**
     * Fetch raw punch data for [from, to].
     * @return array{ok:bool,status:int,body:string,json:?array,error:?string}
     */
    public static function fetch(array $cfg, Carbon $from, Carbon $to): array
    {
        $base = rtrim((string) ($cfg['base_url'] ?: 'https://api.etimeoffice.com/api'), '/');
        $path = trim((string) ($cfg['endpoint'] ?: 'DownloadPunchDataMCID'), '/');
        $url = $base.'/'.$path;
        [$bu, $bp] = self::authPair($cfg);

        try {
            $resp = Http::withBasicAuth($bu, $bp)->timeout(60)->get($url, [
                'Empcode' => $cfg['empcode'] ?: 'ALL',
                'FromDate' => $from->format('d/m/Y_H:i'),
                'ToDate' => $to->format('d/m/Y_H:i'),
            ]);
            $json = null;
            try {
                $json = $resp->json();
            } catch (\Throwable $e) {
            }

            return [
                'ok' => $resp->successful(),
                'status' => $resp->status(),
                'body' => $resp->body(),
                'json' => is_array($json) ? $json : null,
                'error' => $resp->successful() ? null : ('HTTP '.$resp->status()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => $e->getMessage()];
        }
    }

    private static function isList(array $a): bool
    {
        return array_keys($a) === range(0, count($a) - 1);
    }

    private static function pick(array $row, array $keys): ?string
    {
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (array_key_exists($lk, $lower) && trim((string) $lower[$lk]) !== '') {
                return (string) $lower[$lk];
            }
        }

        return null;
    }

    private static function parseDate(string $v): ?Carbon
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y_H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function normDir(?string $v): string
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return 'in';
        }
        if (str_contains($s, 'out') || $s === 'o' || $s === '0') {
            return 'out';
        }

        return 'in';
    }

    /**
     * Normalise a raw response into punch rows (emp_code is the RAW device code,
     * prefix is applied later in import()).
     * @return list<array{emp_code:string,name:?string,punch_at:Carbon,direction:string}>
     */
    public static function parse(?array $json): array
    {
        $out = [];
        if (! is_array($json)) {
            return $out;
        }
        $data = $json['PunchData'] ?? $json['punchData'] ?? $json['Data'] ?? (self::isList($json) ? $json : []);
        if (! is_array($data)) {
            return $out;
        }
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (self::isList($item)) {
                $emp = $item[0] ?? null;
                $name = $item[1] ?? null;
                $dt = $item[2] ?? null;
                $dir = $item[3] ?? null;
            } else {
                $emp = self::pick($item, ['Empcode', 'EmpCode', 'EmployeeCode', 'empcode', 'EMPCODE', 'Code']);
                $name = self::pick($item, ['Name', 'EmpName', 'EmployeeName', 'empname']);
                $dt = self::pick($item, ['DateTime', 'PunchDate', 'Punchdate', 'PunchDateTime', 'DownloadDate', 'punchdatetime', 'Datetime']);
                $dir = self::pick($item, ['INOUT', 'InOut', 'INOUTStatus', 'Status', 'Direction', 'inout']);
            }
            if (! $emp || ! $dt) {
                continue;
            }
            $when = self::parseDate((string) $dt);
            if (! $when) {
                continue;
            }
            $out[] = [
                'emp_code' => trim((string) $emp),
                'name' => $name !== null ? trim((string) $name) : null,
                'punch_at' => $when,
                'direction' => self::normDir($dir !== null ? (string) $dir : null),
            ];
        }

        return $out;
    }

    /**
     * Write parsed punches into attendance_logs, matching employees by
     * (emp_prefix . device_code). Returns counts + unmatched device codes.
     * @return array{imported:int,matched:int,unmatched:array<string,int>}
     */
    public static function import(array $punches, array $cfg): array
    {
        $prefix = trim((string) ($cfg['emp_prefix'] ?? ''));
        $imported = 0;
        $matched = 0;
        $unmatched = [];
        if (! Schema::hasTable('attendance_logs') || ! Schema::hasTable('employees')) {
            return ['imported' => 0, 'matched' => 0, 'unmatched' => $unmatched];
        }
        $cache = [];
        foreach ($punches as $p) {
            $full = $prefix.$p['emp_code'];
            if (! isset($cache[$full])) {
                $cache[$full] = DB::table('employees')
                    ->whereRaw('LOWER(emp_code) = ?', [strtolower($full)])
                    ->first(['id', 'emp_code', 'name', 'tenant_id', 'company_id']);
            }
            $emp = $cache[$full];
            if (! $emp) {
                $unmatched[$full] = ($unmatched[$full] ?? 0) + 1;

                continue;
            }
            $matched++;
            DB::table('attendance_logs')->updateOrInsert(
                [
                    'emp_code' => $emp->emp_code,
                    'punch_at' => $p['punch_at']->format('Y-m-d H:i:s'),
                    'direction' => $p['direction'],
                    'source' => 'etimeoffice',
                ],
                [
                    'emp_name' => $emp->name ?? $p['name'],
                    'log_date' => $p['punch_at']->toDateString(),
                    'tenant_id' => $emp->tenant_id ?? null,
                    'company_id' => $emp->company_id ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $imported++;
        }

        return ['imported' => $imported, 'matched' => $matched, 'unmatched' => $unmatched];
    }
}
