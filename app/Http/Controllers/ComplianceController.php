<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field-force compliance: DRA certification + PCC (police clearance) expiry
 * tracking and automated alerts for collections/recovery agents.
 *
 * The employees table already carries: dra_status, dra_expiry, pcc_status,
 * pcc_expiry, pcc_deadline, is_field_agent, agent_code, portfolio.
 *
 * Two faces:
 *   • scan()  — pure read used by BOTH the in-app "Compliance Alerts" screen
 *               (GET /app/compliance-alerts) and the daily scheduled command,
 *               so the screen and the email digest always agree.
 *   • notify() — called by the scheduled command: groups expiring items per
 *                tenant and emails each tenant's HR/Admin a digest (reusing the
 *                MailService communication engine). Fail-soft.
 *
 * Buckets: expired (<0 days), critical (<=7), soon (<=30). The window is 30
 * days by default.
 */
class ComplianceController extends Controller
{
    public const WINDOW_DAYS = 30;

    /**
     * Scan compliance for a tenant (null = all tenants, for the scheduled run).
     * Returns flat alert rows + bucket counts.
     */
    public static function scan(?int $tenantId, int $windowDays = self::WINDOW_DAYS): array
    {
        if (! Schema::hasTable('employees')) {
            return ['rows' => [], 'counts' => ['expired' => 0, 'critical' => 0, 'soon' => 0]];
        }
        $today = Carbon::today();
        $cols = Schema::getColumnListing('employees');
        $has = fn ($c) => in_array($c, $cols, true);

        // Only meaningful if at least one expiry column exists.
        if (! $has('dra_expiry') && ! $has('pcc_expiry')) {
            return ['rows' => [], 'counts' => ['expired' => 0, 'critical' => 0, 'soon' => 0]];
        }

        $q = DB::table('employees as e')
            ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->when($tenantId, fn ($x) => $x->where('e.tenant_id', $tenantId))
            ->whereNull('e.deleted_at');

        $sel = ['e.id', 'e.tenant_id', 'e.company_id', 'e.emp_code', 'e.name', 'c.name as company'];
        foreach (['email', 'mobile', 'agent_code', 'portfolio', 'branch', 'dra_status', 'dra_expiry', 'pcc_status', 'pcc_expiry', 'pcc_deadline'] as $c) {
            if ($has($c)) {
                $sel[] = 'e.'.$c;
            }
        }
        $emps = $q->get($sel);

        $rows = [];
        $counts = ['expired' => 0, 'critical' => 0, 'soon' => 0];

        $consider = function ($kind, $dateStr, $emp) use ($today, $windowDays, &$rows, &$counts) {
            if (empty($dateStr)) {
                return;
            }
            try {
                $d = Carbon::parse($dateStr)->startOfDay();
            } catch (\Throwable $e) {
                return;
            }
            $days = $today->diffInDays($d, false);   // negative = already expired
            if ($days > $windowDays) {
                return;   // not within the alert window yet
            }
            $bucket = $days < 0 ? 'expired' : ($days <= 7 ? 'critical' : 'soon');
            $counts[$bucket]++;
            $a = (array) $emp;
            $rows[] = [
                'employee_id' => $a['id'],
                'tenant_id' => $a['tenant_id'] ?? null,
                'company_id' => $a['company_id'] ?? null,
                'code' => $a['emp_code'] ?? '',
                'name' => $a['name'] ?? '',
                'company' => $a['company'] ?? '',
                'agentCode' => $a['agent_code'] ?? '',
                'portfolio' => $a['portfolio'] ?? '',
                'email' => $a['email'] ?? '',
                'type' => $kind,            // 'DRA' | 'PCC'
                'date' => $d->toDateString(),
                'days' => $days,            // <0 expired, else days remaining
                'bucket' => $bucket,
            ];
        };

        foreach ($emps as $emp) {
            $a = (array) $emp;
            $consider('DRA', $a['dra_expiry'] ?? null, $emp);
            $consider('PCC', $a['pcc_expiry'] ?? null, $emp);
        }

        // B1/B3 — structured DRA certificates live in dra_certs (the DRA Certifications
        // screen). Scan their expiry too so the radar and digest cover them.
        if (Schema::hasTable('dra_certs')) {
            $dq = DB::table('dra_certs as d')
                ->join('employees as e', 'e.id', '=', 'd.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tenantId, fn ($x) => $x->where('d.tenant_id', $tenantId))
                ->whereNull('e.deleted_at')
                ->whereNotNull('d.expiry');
            $dsel = ['d.expiry as dra_expiry', 'e.id', 'e.tenant_id', 'e.company_id', 'e.emp_code', 'e.name', 'c.name as company'];
            foreach (['email', 'agent_code', 'portfolio'] as $c) {
                if ($has($c)) {
                    $dsel[] = 'e.'.$c;
                }
            }
            foreach ($dq->get($dsel) as $dc) {
                $consider('DRA', $dc->dra_expiry ?? null, $dc);
            }
        }

        // C3 — BGV re-verification scheduler: surface overdue / upcoming re-verifications.
        if (Schema::hasTable('bgv') && Schema::hasColumn('bgv', 'next_due')) {
            $bq = DB::table('bgv as b')
                ->join('employees as e', 'e.id', '=', 'b.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tenantId, fn ($x) => $x->where('b.tenant_id', $tenantId))
                ->whereNull('e.deleted_at')
                ->whereNotNull('b.next_due');
            $bsel = ['b.next_due as bgv_due', 'e.id', 'e.tenant_id', 'e.company_id', 'e.emp_code', 'e.name', 'c.name as company'];
            foreach (['email', 'agent_code', 'portfolio'] as $c) {
                if ($has($c)) {
                    $bsel[] = 'e.'.$c;
                }
            }
            foreach ($bq->get($bsel) as $bc) {
                $consider('BGV re-verify', $bc->bgv_due ?? null, $bc);
            }
        }

        // Sort: most urgent first (smallest days, expired before soon).
        usort($rows, fn ($x, $y) => $x['days'] <=> $y['days']);

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** In-app screen data: alerts for the current user's tenant. */
    public function alerts(Request $request)
    {
        try {
            $tenantId = $request->user()->tenant_id;
            $window = (int) $request->query('days', self::WINDOW_DAYS);
            $res = self::scan($tenantId, $window > 0 ? $window : self::WINDOW_DAYS);

            return response()->json([
                'rows' => $res['rows'],
                'counts' => $res['counts'],
                'window' => $window > 0 ? $window : self::WINDOW_DAYS,
                'canManage' => $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'counts' => ['expired' => 0, 'critical' => 0, 'soon' => 0], 'error' => $e->getMessage()]);
        }
    }

    /** Manual "send alerts now" trigger (admin/HR) — same as the daily job. */
    public function runNow(Request $request)
    {
        try {
            abort_unless($request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']), 403);
            $tenantId = $request->user()->tenant_id;
            $sent = self::notify($tenantId);

            return response()->json(['ok' => true, 'queued' => $sent, 'message' => $sent.' compliance digest email(s) queued']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Build per-tenant digests and queue them to that tenant's HR/Admin users.
     * Returns the number of digest emails queued. $tenantId null = every tenant.
     */
    public static function notify(?int $tenantId = null): int
    {
        $queued = 0;
        // Determine which tenants to process.
        $tenantIds = $tenantId ? [$tenantId] : DB::table('employees')->whereNull('deleted_at')->distinct()->pluck('tenant_id')->filter()->all();

        foreach ($tenantIds as $tid) {
            $res = self::scan((int) $tid);
            if (empty($res['rows'])) {
                continue;
            }
            $recipients = self::recipientsFor((int) $tid);
            if (empty($recipients)) {
                continue;
            }

            // Build a compact text summary of the most urgent items (top 25).
            $c = $res['counts'];
            $top = array_slice($res['rows'], 0, 25);
            $bodyLines = [];
            foreach ($top as $r) {
                $when = $r['days'] < 0
                    ? 'EXPIRED '.abs($r['days']).'d ago'
                    : 'in '.$r['days'].'d';
                $bodyLines[] = $r['type'].' · '.$r['name'].' ('.$r['code'].')'
                    .($r['company'] ? ' · '.$r['company'] : '')
                    .' — '.$r['date'].' ('.$when.')';
            }
            $body = implode("\n", $bodyLines);
            if (count($res['rows']) > count($top)) {
                $body .= "\n… and ".(count($res['rows']) - count($top)).' more. Open Compliance Alerts in SmartPRS for the full list.';
            }

            $lines = [
                'Expired' => (string) $c['expired'],
                'Due within 7 days' => (string) $c['critical'],
                'Due within 30 days' => (string) $c['soon'],
            ];

            foreach ($recipients as $rcpt) {
                MailService::queue([
                    'tenant_id' => (int) $tid,
                    'company_id' => $rcpt['company_id'] ?? null,
                    'to' => $rcpt['email'],
                    'to_name' => $rcpt['name'] ?? '',
                    'subject' => 'Compliance alert: '.($c['expired'] + $c['critical']).' urgent, '.$c['soon'].' upcoming (DRA/PCC)',
                    'heading' => 'Field-force compliance alerts',
                    'intro' => 'The following DRA / PCC certifications are expired or expiring soon. Please action renewals.',
                    'lines' => $lines,
                    'body' => $body,
                    'kind' => 'compliance.digest',
                ]);
                $queued++;
            }
        }

        return $queued;
    }

    /** HR/Admin recipient emails for a tenant (from the users table + Spatie roles). */
    private static function recipientsFor(int $tenantId): array
    {
        try {
            if (! Schema::hasTable('users')) {
                return [];
            }
            // Resolve user ids holding admin/hr roles via Spatie's pivot tables,
            // guarding for installs where those tables differ.
            $roleNames = ['super_admin', 'admin', 'hr_manager'];
            $userIds = [];
            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');
                $userIds = DB::table('model_has_roles')
                    ->whereIn('role_id', $roleIds)
                    ->where('model_type', 'App\\Models\\User')
                    ->pluck('model_id')->all();
            }

            $q = DB::table('users')->where('tenant_id', $tenantId)->whereNotNull('email');
            if (! empty($userIds)) {
                $q->whereIn('id', $userIds);
            } else {
                // No role pivots resolved — be conservative and don't blast everyone.
                return [];
            }

            return $q->get(['name', 'email', 'company_id'])->map(fn ($u) => [
                'name' => $u->name,
                'email' => $u->email,
                'company_id' => $u->company_id ?? null,
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * F1 — per-agent compliance score (0–100) from internal SmartPRS data:
     * DRA valid (25) + PCC valid (20) + BGV clear (20) + NDA signed (15) +
     * no open complaints (20). Used by the incentive gate and the scorecard report.
     */
    public static function scoreFor(int $employeeId, ?int $tid = null): int
    {
        $score = 0;
        $today = Carbon::today()->toDateString();
        if (Schema::hasTable('dra_certs')) {
            $ok = DB::table('dra_certs')->where('employee_id', $employeeId)->where('status', 'verified')
                ->where(fn ($q) => $q->whereNull('expiry')->orWhere('expiry', '>=', $today))->exists();
            if ($ok) {
                $score += 25;
            }
        }
        if (Schema::hasTable('employees')) {
            $e = DB::table('employees')->where('id', $employeeId)->first();
            if ($e) {
                $pccOk = (($e->pcc_status ?? '') === 'verified') && (empty($e->pcc_expiry) || $e->pcc_expiry >= $today);
                if ($pccOk) {
                    $score += 20;
                }
            }
        }
        if (Schema::hasTable('bgv')) {
            if (DB::table('bgv')->where('employee_id', $employeeId)->where('status', 'clear')->exists()) {
                $score += 20;
            }
        }
        if (Schema::hasTable('letters')) {
            $ndaOk = DB::table('letters')->where('employee_id', $employeeId)->where('letter_type', 'nda')
                ->where('is_template', 0)->where('status', 'signed')->exists();
            if ($ndaOk) {
                $score += 15;
            }
        }
        if (Schema::hasTable('complaints')) {
            $open = DB::table('complaints')->where('employee_id', $employeeId)
                ->whereIn('status', ['open', 'pending', 'in_progress'])->count();
            $score += $open > 0 ? 0 : 20;
        } else {
            $score += 20;
        }

        return min(100, max(0, $score));
    }
}
