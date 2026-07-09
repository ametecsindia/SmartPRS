<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Generate Payroll (rev 36) — the FRONT of the payroll flow, now real.
 *
 * Creates a real `payroll_runs` row (status=draft) plus one `payslips` row per
 * active employee for a chosen company + month, computing each salary from the
 * employee CTC and the tenant's configured statutory rates (reusing
 * AppDataController::computeSlip so the math matches payslips/PF/ESIC/TDS
 * everywhere else). The draft run then flows straight into Salary Approval
 * (HR → Finance → disburse → bank file), all of which is already real.
 *
 * Attendance (LOP): optional. When 'lop' is on, each employee's pay is prorated
 * by present-days / working-days for the month (present-days = distinct punch
 * dates in attendance_logs; working-days = calendar days minus Sundays). An
 * employee with NO punches at all is treated as FULL-paid, so a company that
 * doesn't use biometric attendance is never accidentally zeroed. Public
 * holidays / approved-leave nuance is intentionally NOT modelled yet (that needs
 * the Holidays + Leave-Types master data, still on the backlog as C2) — LOP here
 * is a transparent, opt-in proration, off by default.
 *
 * Conventions honoured: tenant-scoped, admin/HR guarded, fail-soft JSON,
 * schema-safe inserts via ApprovalService::safeRow, idempotent per month/company.
 */
class PayrollGenController extends Controller
{
    // Default shift window used when a company's Late Policy doesn't set its own.
    private const SHIFT_START = '09:30';
    private const SHIFT_END = '18:30';

    /** Resolve a company the current tenant may run payroll for. */
    private function resolveCompany(Request $request, $companyId): ?object
    {
        $tid = $request->user()->tenant_id;

        return DB::table('companies')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('id', (int) $companyId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** Validate + normalise a 'YYYY-MM' month string; null if invalid. */
    private function normMonth(?string $month): ?string
    {
        if (! $month) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m', substr($month, 0, 7))->format('Y-m');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * rev172 — configurable weekly offs (Ejaz): is this date a weekly off per
     * Statutory Settings? weekly_off_day (default sunday) + sat_off_mode
     * (none | all | 2_4 | 1_3 — Nth Saturdays of the month off).
     */
    public static function isWeekOff(Carbon $d, array $r): bool
    {
        $map = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $off = $map[strtolower((string) ($r['weekly_off_day'] ?? 'sunday'))] ?? 0;
        if ($d->dayOfWeek === $off) {
            return true;
        }
        if ($d->dayOfWeek === Carbon::SATURDAY) {
            $mode = (string) ($r['sat_off_mode'] ?? 'none');
            if ($mode === 'all') {
                return true;
            }
            if ($mode === '2_4' || $mode === '1_3') {
                $nth = (int) ceil($d->day / 7); // 1st–5th Saturday of the month
                return $mode === '2_4' ? ($nth === 2 || $nth === 4) : ($nth === 1 || $nth === 3);
            }
        }

        return false;
    }

    /**
     * rev172 (M1) — working days in [fromDay..toDay] of a month, excluding weekly
     * offs and working-day holidays. Used to prorate a mid-month joiner: their
     * denominator is the working days available FROM their date of joining, not
     * the whole month, so an employee who joined on the 15th isn't underpaid.
     */
    private function workingDaysInRange(Carbon $start, int $fromDay, int $toDay, array $rates, array $holidays): int
    {
        $n = 0;
        for ($d = max(1, $fromDay); $d <= $toDay; $d++) {
            $cur = Carbon::create($start->year, $start->month, $d);
            if (self::isWeekOff($cur, $rates) || isset($holidays[$cur->toDateString()])) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /** [startDate, daysInMonth, workingDays(excl. Sundays), endDateString] for a month. */
    private function monthMeta(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $days = $start->daysInMonth;
        $working = 0;
        for ($d = 1; $d <= $days; $d++) {
            if (Carbon::create($start->year, $start->month, $d)->dayOfWeek !== Carbon::SUNDAY) {
                $working++;
            }
        }

        return [$start, $days, max(1, $working), $start->copy()->endOfMonth()->toDateString()];
    }

    /** Distinct punch dates for an employee in the month (0 if none / no table). */
    private function presentDays(string $empCode, string $month, string $endDate, ?int $tid = null): int
    {
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return 0;
        }
        try {
            // rev172 — tenant-scoped: emp codes (EMP-XXXX) repeat across tenants,
            // so without this filter one tenant's payroll could count another
            // tenant's punches.
            return (int) DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$month.'-01', $endDate])
                ->distinct()
                ->count('log_date');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** rev173 — the distinct punch DATES themselves (for night-allowance counting). */
    private function presentDates(string $empCode, string $month, string $endDate, ?int $tid = null): array
    {
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return [];
        }
        try {
            return DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$month.'-01', $endDate])
                ->distinct()
                ->pluck('log_date')
                ->map(fn ($d) => substr((string) $d, 0, 10))
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Load every late-policy row that could apply to this company (resolved per-employee later). */
    private function latePolicyRows($company, $tid)
    {
        if (! Schema::hasTable('late_policy')) {
            return collect();
        }

        // company_name / company_id are wizard-added columns — guard each so a
        // fresh DB (no Late Policy saved yet) never 42S22s (Ejaz, 4 Jun 2026).
        $hasCoId = Schema::hasColumn('late_policy', 'company_id');
        $hasCoName = Schema::hasColumn('late_policy', 'company_name');

        return DB::table('late_policy')
            ->when($tid && Schema::hasColumn('late_policy', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
            ->when($hasCoId || $hasCoName, function ($q) use ($company, $hasCoId, $hasCoName) {
                $q->where(function ($w) use ($company, $hasCoId, $hasCoName) {
                    $first = true;
                    if ($hasCoId) {
                        $w->where('company_id', $company->id)->orWhereNull('company_id');
                        $first = false;
                    }
                    if ($hasCoName) {
                        $first ? $w->where('company_name', $company->name) : $w->orWhere('company_name', $company->name);
                    }
                });
            })
            ->get();
    }

    /** Pick the most specific policy (employee > team > company) and normalise it. */
    private function resolvePolicy($rows, ?string $empCode, ?string $teamName): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }
        $pick = null;
        $rank = -1;
        foreach ($rows as $r) {
            $a = (array) $r;
            $scope = $a['scope'] ?? 'company';
            $target = (string) ($a['scope_target'] ?? '');
            $thisRank = -1;
            if ($scope === 'employee' && $empCode && strcasecmp($target, $empCode) === 0) {
                $thisRank = 2;
            } elseif ($scope === 'team' && $teamName && $target !== '' && strcasecmp($target, $teamName) === 0) {
                $thisRank = 1;
            } elseif ($scope === 'company' || $scope === '' || $scope === null) {
                $thisRank = 0;
            }
            if ($thisRank > $rank) {
                $rank = $thisRank;
                $pick = $a;
            }
        }
        if (! $pick || $rank < 0) {
            return null;
        }

        return [
            'mode' => $pick['mode'] ?: 'simple',
            'shift_start' => $pick['shift_start'] ?: self::SHIFT_START,
            'shift_end' => $pick['shift_end'] ?: self::SHIFT_END,
            'grace' => (int) ($pick['grace_min'] ?? 0),
            'free' => (int) ($pick['lates_before_cut'] ?? 0),
            'cut_mode' => $pick['cut_mode'] ?: 'none',
            'cut_n' => max(1, (int) ($pick['cut_n'] ?? 3)),
            'full_min' => ((float) ($pick['full_day_hours'] ?: 9)) * 60,
            'half_min' => ((float) ($pick['half_day_hours'] ?: 4.5)) * 60,
            'l1_min' => (int) ($pick['l1_min'] ?? 0),
            'l1_cut' => (float) ($pick['l1_cut'] ?? 0),
            'l2_min' => (int) ($pick['l2_min'] ?? 0),
            'l2_cut' => (float) ($pick['l2_cut'] ?? 0),
            'l3_min' => (int) ($pick['l3_min'] ?? 0),
            'l3_cut' => (float) ($pick['l3_cut'] ?? 0),
            'break_budget' => (int) ($pick['break_budget'] ?? 0),
            'break_cut' => $pick['break_cut'] ?: 'none',
        ];
    }

    /** Per-day stats for an employee: 'Y-m-d' => ['firstIn'=>Carbon|null,'worked'=>min,'break'=>min]. */
    private function dayStats(string $empCode, string $month, string $endDate, ?int $tid = null): array
    {
        $out = [];
        if (! $empCode || ! Schema::hasTable('attendance_logs')) {
            return $out;
        }
        try {
            $punches = DB::table('attendance_logs')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid)) // rev172 — tenant-scoped (see presentDays)
                ->where('emp_code', $empCode)
                ->whereBetween('log_date', [$month.'-01', $endDate])
                ->orderBy('punch_at')
                ->get(['log_date', 'punch_at', 'direction']);
        } catch (\Throwable $e) {
            return $out;
        }
        $byDay = [];
        foreach ($punches as $p) {
            if (! $p->punch_at) {
                continue;
            }
            $d = substr((string) $p->log_date, 0, 10);
            $byDay[$d][] = $p;
        }
        foreach ($byDay as $d => $ps) {
            // rev173g — SAME pairing rule as the Attendance Report (pairPunches):
            // direction-aware only when the day has BOTH an 'in' and an 'out'
            // (repeat INs while open = double-tap → ignored; orphan OUTs ignored);
            // otherwise chronological alternation. Previously this trusted the
            // direction flag blindly — a day whose punches were all marked 'in'
            // (wrong device flags) computed ZERO worked minutes and payroll cut pay.
            $rows = [];
            foreach ($ps as $p) {
                $rows[] = ['t' => Carbon::parse($p->punch_at), 'dir' => strtolower(trim((string) ($p->direction ?? '')))];
            }
            usort($rows, fn ($a, $b) => $a['t']->getTimestamp() <=> $b['t']->getTimestamp());
            $hasIn = false;
            $hasOut = false;
            foreach ($rows as $r) {
                if ($r['dir'] === 'in') {
                    $hasIn = true;
                } elseif ($r['dir'] === 'out') {
                    $hasOut = true;
                }
            }
            $firstIn = null;
            $worked = 0;
            $break = 0;
            if ($hasIn && $hasOut) {
                $open = null;
                $prevOut = null;
                foreach ($rows as $r) {
                    if ($r['dir'] === 'out') {
                        if ($open !== null) {
                            $worked += max(0, $open->diffInMinutes($r['t']));
                            $open = null;
                            $prevOut = $r['t'];
                        }
                    } else {
                        if ($firstIn === null) {
                            $firstIn = $r['t'];
                        }
                        if ($open === null) {
                            if ($prevOut !== null) {
                                $break += max(0, $prevOut->diffInMinutes($r['t']));
                                $prevOut = null;
                            }
                            $open = $r['t'];
                        }
                        // repeated IN while open → double-tap, ignore
                    }
                }
            } else {
                $nD = count($rows);
                $firstIn = $nD ? $rows[0]['t'] : null;
                for ($i = 0; $i + 1 < $nD; $i += 2) {
                    $worked += max(0, $rows[$i]['t']->diffInMinutes($rows[$i + 1]['t']));
                    if ($i + 2 < $nD) {
                        $break += max(0, $rows[$i + 1]['t']->diffInMinutes($rows[$i + 2]['t']));
                    }
                }
            }
            $out[$d] = ['firstIn' => $firstIn, 'worked' => $worked, 'break' => $break];
        }

        return $out;
    }

    /** Apply a resolved policy to a month of day-stats → ['cut','lateCut','breakCut','late']. */
    private function attendanceCut(array $days, array $pol, array $dayShifts = []): array
    {
        ksort($days);
        $mode = $pol['mode'];
        $lateCut = 0.0;
        $breakCut = 0.0;
        $lateCount = 0;
        $lateSeen = 0;
        $free = max(0, $pol['free']);
        foreach ($days as $d => $st) {
            if (! $st['firstIn']) {
                continue;
            }
            // rev173 — per-day Working Shift override (roster > employee default).
            // The shift supplies TIMINGS (+ optional grace/hours/break overrides);
            // the Late Policy keeps supplying the RULES. A roster week-off skips
            // late/break evaluation for that day entirely.
            $sh = $dayShifts[$d] ?? null;
            if ($sh && ! empty($sh['off'])) {
                continue;
            }
            $dayStart = ($sh && ! empty($sh['start'])) ? $sh['start'] : $pol['shift_start'];
            $dayGrace = ($sh && $sh['grace'] !== null) ? $sh['grace'] : $pol['grace'];
            $dayFullMin = ($sh && $sh['full_hours']) ? $sh['full_hours'] * 60 : $pol['full_min'];
            $dayHalfMin = ($sh && $sh['half_hours']) ? $sh['half_hours'] * 60 : $pol['half_min'];
            $dayBreakBudget = ($sh && $sh['break_budget'] !== null) ? $sh['break_budget'] : $pol['break_budget'];
            $cutoff = Carbon::parse($d.' '.$dayStart)->addMinutes($dayGrace);
            $isLate = $st['firstIn']->gt($cutoff);
            $lateMin = $isLate ? $cutoff->diffInMinutes($st['firstIn']) : 0;

            if ($mode === 'net_hours') {
                // Made-up time: full pay if total worked meets the full-day target, even if late.
                $w = $st['worked'];
                if ($w < $dayHalfMin) {
                    $lateCut += 1.0;
                } elseif ($w < $dayFullMin) {
                    $lateCut += 0.5;
                }
            } elseif ($mode === 'tiered') {
                if ($isLate) {
                    $lateCount++;
                    $lateSeen++;
                    if ($lateSeen > $free) {
                        $lateCut += $this->tierCut($lateMin, $pol);
                    }
                }
            } else { // simple
                if ($isLate) {
                    $lateCount++;
                }
            }

            // Break-budget deduction (any mode).
            if ($pol['break_cut'] !== 'none' && $dayBreakBudget > 0 && $st['break'] > $dayBreakBudget) {
                if ($pol['break_cut'] === 'half_day') {
                    $breakCut += 0.5;
                } elseif ($pol['break_cut'] === 'per_30min') {
                    $excess = $st['break'] - $dayBreakBudget;
                    $breakCut += ceil($excess / 30) * 0.25;
                }
            }
        }

        if ($mode === 'simple') {
            $lateCut = $this->simpleCut(max(0, $lateCount - $free), $pol);
        }

        return ['cut' => $lateCut + $breakCut, 'lateCut' => $lateCut, 'breakCut' => $breakCut, 'late' => $lateCount];
    }

    /** Tiered: day-cut for how many minutes late (highest crossed level wins). */
    private function tierCut(int $lateMin, array $pol): float
    {
        if ($pol['l3_min'] > 0 && $lateMin >= $pol['l3_min']) {
            return $pol['l3_cut'];
        }
        if ($pol['l2_min'] > 0 && $lateMin >= $pol['l2_min']) {
            return $pol['l2_cut'];
        }
        if ($pol['l1_min'] > 0 && $lateMin >= $pol['l1_min']) {
            return $pol['l1_cut'];
        }

        return $pol['l1_cut']; // late beyond grace but below L1 threshold → treat as L1
    }

    /** Simple: excess lates → day-cut by the flat rule. */
    private function simpleCut(int $excessLates, array $pol): float
    {
        if ($excessLates <= 0) {
            return 0.0;
        }
        switch ($pol['cut_mode']) {
            case 'half_day_per_late':
                return $excessLates * 0.5;
            case 'full_day_per_late':
                return (float) $excessLates;
            case 'one_day_per_n':
                return (float) intdiv($excessLates, $pol['cut_n']);
            default:
                return 0.0;
        }
    }

    /** Plain-English explanation of how one employee's pay was computed (for the payslip + sheet). */
    private function calcNote(float $ctc, array $s, bool $lop, int $present, float $leave, int $working, float $factor, int $lateDays, float $lateCut, float $breakCut, ?array $pol, float $commission = 0.0, array $commLines = []): string
    {
        $rs = fn ($x) => 'Rs '.number_format((float) $x, 0);
        $dy = fn ($x) => rtrim(rtrim(number_format((float) $x, 2), '0'), '.');
        $p = [];
        $p[] = 'Monthly gross from CTC '.$rs($ctc).' / 12 = '.$rs(round($ctc / 12, 2)).'.';
        if ($lop) {
            $att = 'Attendance: '.$present.' present day(s)';
            if ($leave > 0) {
                $att .= ' + '.$dy($leave).' paid-leave day(s)';
            }
            $att .= ' of '.$working.' working days.';
            $p[] = $att;
            if ($lateCut > 0) {
                $mode = $pol['mode'] ?? 'simple';
                $modeLabel = $mode === 'tiered' ? 'tiered L1/L2/L3' : ($mode === 'net_hours' ? 'net working hours' : 'simple late rule');
                $p[] = 'Late: '.$lateDays.' late day(s) -> -'.$dy($lateCut).' day cut ('.$modeLabel.').';
            }
            if ($breakCut > 0) {
                $p[] = 'Breaks over budget -> -'.$dy($breakCut).' day cut.';
            }
            if ($factor < 0.9999) {
                $p[] = 'Paid '.$dy($factor * $working).' of '.$working.' days, so gross is prorated to '.$rs($s['gross']).' (x'.number_format($factor, 3).').';
            } else {
                $p[] = 'Full attendance - no proration.';
            }
        }
        if (! empty($s['earnings'])) {
            $ep = [];
            foreach ($s['earnings'] as $en => $ea) {
                $ep[] = $en.' '.$rs($ea);
            }
            $p[] = 'Earnings (per salary structure): '.implode(', ', $ep).'.';
        } else {
            $p[] = 'Earnings: Basic '.$rs($s['basic']).' (50% of gross), HRA '.$rs($s['hra']).' (40% of basic), Special Allowance '.$rs($s['special']).'.';
        }
        if ($commission > 0) {
            $p[] = 'Commission added (approved, due this month): '.$rs($commission).'.';
            // rev 84 (Ejaz): line summary of every commission inside this slip.
            if ($commLines) {
                $p[] = 'Commission detail: '.implode(' | ', $commLines).'.';
            }
        }
        if (! empty($s['deductions'])) {
            $dp = [];
            foreach ($s['deductions'] as $dn => $da) {
                $dp[] = $dn.' '.$rs($da);
            }
            $p[] = 'Deductions: '.implode(', ', $dp).' = -'.$rs($s['total_ded']).'.';
        } else {
            $p[] = 'Deductions: PF '.$rs($s['pf']).', ESI '.$rs($s['esi']).', PT '.$rs($s['pt']).', TDS '.$rs($s['tds']).' = -'.$rs($s['total_ded']).'.';
        }
        if ($commission > 0) {
            $p[] = 'Net pay = gross + commission - deductions = '.$rs($s['net'] + $commission).'.';
        } else {
            $p[] = 'Net pay = gross - deductions = '.$rs($s['net']).'.';
        }
        $p[] = 'Incentive schemes (if any) are processed separately.';

        return implode(' ', $p);
    }

    /**
     * APPROVED commissions due in this payroll month, keyed by employee_id.
     * rev 84 (Ejaz): the PAYOUT DATE decides which month's payslip pays an
     * entry; entries without a payout date fall back to their earned month
     * (cycle_month) — full backward compatibility.
     * Returns ['sum' => [empId => net total], 'rows' => [empId => [entry...]]].
     */
    private function commissionByEmployee(object $company, ?int $tid, string $month): array
    {
        $out = ['sum' => [], 'rows' => []];
        if (! Schema::hasTable('commissions') || ! Schema::hasColumn('commissions', 'amount')) {
            return $out;
        }
        $hasCycle = Schema::hasColumn('commissions', 'cycle_month');
        try {
            $cols = ['id', 'employee_id', 'amount'];
            foreach (['cycle_month', 'payout_date', 'payout_method', 'purpose', 'portfolio', 'gross_amount', 'tds_amount'] as $c) {
                if (Schema::hasColumn('commissions', $c)) {
                    $cols[] = $c;
                }
            }
            $rows = DB::table('commissions')
                ->when($tid && Schema::hasColumn('commissions', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('commissions', 'company_id'), fn ($q) => $q->where('company_id', $company->id))
                ->where('status', 'approved')
                // rev172 (M3) — a commission already LOCKED into a payslip must never
                // fold into another month's run (e.g. if its payout_date is edited
                // after locking). Regenerating the SAME month first clears the lock,
                // so this still lets a re-generated month re-include correctly.
                ->when(Schema::hasColumn('commissions', 'locked_at'), fn ($q) => $q->whereNull('locked_at'))
                ->get($cols);
            foreach ($rows as $r) {
                if (! $r->employee_id) {
                    continue;
                }
                // rev 85 (Ejaz): 'separate' payout entries NEVER fold into the
                // payslip — they are paid through the disbursement ledger.
                if ((($r->payout_method ?? '') ?: 'with_salary') === 'separate') {
                    continue;
                }
                $payout = trim((string) ($r->payout_date ?? ''));
                if ($payout !== '') {
                    // Payout date rules: pay in the month it falls in.
                    if (substr($payout, 0, 7) !== $month) {
                        continue;
                    }
                } elseif ($hasCycle) {
                    // No payout date → earned month (legacy behaviour).
                    $cm = trim((string) ($r->cycle_month ?? ''));
                    if ($cm === '') {
                        continue;
                    }
                    try {
                        if (Carbon::parse($cm)->format('Y-m') !== $month) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
                $eid = (int) $r->employee_id;
                $out['sum'][$eid] = ($out['sum'][$eid] ?? 0) + (float) $r->amount;
                $out['rows'][$eid][] = [
                    'id' => (int) $r->id,
                    'label' => trim((string) (($r->purpose ?? '') ?: 'Commission'))
                        .(! empty($r->portfolio) ? ' ('.$r->portfolio.')' : '')
                        .(! empty($r->gross_amount) ? ': gross Rs '.number_format((float) $r->gross_amount, 2).' - TDS Rs '.number_format((float) ($r->tds_amount ?? 0), 2).' =' : ':')
                        .' Rs '.number_format((float) $r->amount, 2)
                        .($payout !== '' ? ' (payout '.substr($payout, 0, 10).')' : ''),
                    'purpose' => trim((string) (($r->purpose ?? '') ?: 'Commission')) ?: 'Commission',
                    'amount' => (float) $r->amount,
                ];
            }
        } catch (\Throwable $e) {
            return ['sum' => [], 'rows' => []];
        }

        return $out;
    }

    /** All candidate salary components for the company + tenant-wide (resolved per-employee in the loop). */
    private function componentRows($company, $tid)
    {
        if (! Schema::hasTable('salary_components')) {
            return collect();
        }
        try {
            return DB::table('salary_components')
                ->when($tid && Schema::hasColumn('salary_components', 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                ->when(Schema::hasColumn('salary_components', 'company_name'), function ($x) use ($company) {
                    $x->where(function ($y) use ($company) {
                        $y->where('company_name', $company->name)->orWhereNull('company_name')->orWhere('company_name', '');
                    });
                })
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Most-specific component set for an employee: employee > team > company/tenant-wide. */
    private function resolveComponents($rows, ?string $empCode, ?string $teamName)
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $scopeOf = fn ($r) => strtolower((string) (((array) $r)['scope'] ?? '')) ?: 'company';
        $targetOf = fn ($r) => (string) (((array) $r)['scope_target'] ?? '');

        if ($empCode) {
            $emp = $rows->filter(fn ($r) => $scopeOf($r) === 'employee' && strcasecmp($targetOf($r), $empCode) === 0)->values();
            if ($emp->isNotEmpty()) {
                return $emp;
            }
        }
        if ($teamName) {
            $team = $rows->filter(fn ($r) => $scopeOf($r) === 'team' && $targetOf($r) !== '' && strcasecmp($targetOf($r), $teamName) === 0)->values();
            if ($team->isNotEmpty()) {
                return $team;
            }
        }

        return $rows->filter(fn ($r) => in_array($scopeOf($r), ['company', ''], true))->values();
    }

    /** Working-day company holidays in the month: map of 'Y-m-d' => true. */
    private function holidayDates(?int $tid, string $month, string $endDate, array $rates = []): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }
        try {
            $rows = DB::table('holidays')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereBetween('date', [$month.'-01', $endDate])
                ->pluck('date')->all();
            $out = [];
            foreach ($rows as $d) {
                $ds = substr((string) $d, 0, 10);
                // A holiday on a weekly off is already a non-working day; only count
                // working-day holidays (they remove a day from the LOP denominator).
                if (! self::isWeekOff(Carbon::parse($ds), $rates)) {
                    $out[$ds] = true;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Approved PAID-leave working-days for an employee within the month. */
    private function paidLeaveDays(int $empId, ?int $tid, string $month, string $endDate, array $holidays, array $rates = []): float
    {
        if (! Schema::hasTable('leaves')) {
            return 0.0;
        }
        try {
            $start = $month.'-01';
            $rows = DB::table('leaves')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('employee_id', $empId)
                ->where('status', 'approved')
                ->where('from_date', '<=', $endDate)
                ->where('to_date', '>=', $start)
                ->get(['from_date', 'to_date', 'type_id']);
            if ($rows->isEmpty()) {
                return 0.0;
            }
            $paidByType = Schema::hasTable('leave_types')
                ? DB::table('leave_types')->pluck('paid', 'id')->all()
                : [];
            $count = 0.0;
            foreach ($rows as $lv) {
                // Unknown type → treat as paid (conservative: credits the day).
                $paid = $lv->type_id === null ? true : ((int) ($paidByType[$lv->type_id] ?? 1) === 1);
                if (! $paid) {
                    continue;   // Loss-of-Pay leave does NOT count as a present day
                }
                $fromS = substr((string) $lv->from_date, 0, 10);
                $toS = substr((string) $lv->to_date, 0, 10);
                $from = Carbon::parse($fromS < $start ? $start : $fromS);
                $to = Carbon::parse($toS > $endDate ? $endDate : $toS);
                for ($c = $from->copy(); $c->lte($to); $c->addDay()) {
                    if (self::isWeekOff($c, $rates) || isset($holidays[$c->toDateString()])) {
                        continue;
                    }
                    $count += 1;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Build the per-employee salary lines for a month. Returns
     * [rows[], totals, skipped, meta]. Pure computation — no writes.
     */
    private function compute(Request $request, object $company, string $month, bool $lop): array
    {
        $tid = $request->user()->tenant_id;
        $rates = SettingsController::rates($tid);
        [$start, $daysInMonth, , $endDate] = $this->monthMeta($month);

        // Working days = calendar days minus weekly offs (configurable) minus working-day holidays.
        $holidays = $lop ? $this->holidayDates($tid, $month, $endDate, $rates) : [];
        $working = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $cur = Carbon::create($start->year, $start->month, $d);
            if (self::isWeekOff($cur, $rates) || isset($holidays[$cur->toDateString()])) {
                continue;
            }
            $working++;
        }
        $working = max(1, $working);

        $empSel = ['id', 'emp_code', 'name', 'ctc'];
        if (Schema::hasColumn('employees', 'team')) {
            $empSel[] = 'team';
        }
        if (Schema::hasColumn('employees', 'shift')) {
            $empSel[] = 'shift'; // rev173 — default Working Shift (name)
        }
        if (Schema::hasColumn('employees', 'doj')) {
            $empSel[] = 'doj'; // rev172 (M1) — needed to prorate mid-month joiners
        }
        $emps = DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('emp_code')
            ->get($empSel);

        // All candidate late policies for this company (resolved per-employee in the loop).
        $polRows = $lop ? $this->latePolicyRows($company, $tid) : collect();

        // rev173 — Working Shifts: named timings + roster overrides. Resolved
        // per employee per day; a resolved shift's timings replace the Late
        // Policy's shift_start/end (the policy keeps owning the RULES).
        // Night shifts (end <= start) additionally pay night_allowance per
        // night actually worked.
        $shiftDefs = \App\Services\ShiftResolver::shifts($tid);
        $rosterMap = $shiftDefs ? \App\Services\ShiftResolver::rosterMap($tid, $month.'-01', $endDate) : [];
        $anyNight = false;
        foreach ($shiftDefs as $sd) {
            if ($sd['night'] && $sd['allowance'] > 0) {
                $anyNight = true;
                break;
            }
        }

        // Approved commissions DUE in this month (payout date first, earned
        // month fallback) → added to each employee's pay. rev 84.
        $commData = $this->commissionByEmployee($company, $tid, $month);
        $commByEmp = $commData['sum'];
        $commRowsByEmp = $commData['rows'];

        // Candidate salary components (resolved per-employee in the loop: employee > team > company).
        $compRows = $this->componentRows($company, $tid);

        $rows = [];
        $skipped = 0;
        $tg = 0.0;
        $td = 0.0;
        $tn = 0.0;
        foreach ($emps as $e) {
            $ctc = (float) $e->ctc;
            if ($ctc <= 0) {
                $skipped++;

                continue;   // no CTC set → cannot compute a salary; flagged to the user
            }
            $present = $lop ? $this->presentDays($e->emp_code, $month, $endDate, $tid) : 0;
            $leave = ($lop && $present > 0) ? $this->paidLeaveDays((int) $e->id, $tid, $month, $endDate, $holidays, $rates) : 0.0;
            // rev172 (M1) — mid-month joiner: prorate against the working days
            // available FROM the date of joining, not the whole month.
            $empWorking = $working;
            $dojField = property_exists($e, 'doj') ? $e->doj : null;
            if ($dojField) {
                try {
                    $doj = Carbon::parse(substr((string) $dojField, 0, 10));
                    if ($doj->format('Y-m') === $month && $doj->day > 1) {
                        $empWorking = max(1, $this->workingDaysInRange($start, $doj->day, $daysInMonth, $rates, $holidays));
                    }
                } catch (\Throwable $e2) {
                    // unparseable DOJ → fall back to full-month working days
                }
            }
            $lateCut = 0.0;
            $breakCut = 0.0;
            $lateDays = 0;
            $factor = 1.0;
            $pol = null;
            if ($lop && $present > 0) {
                // Resolve the most specific policy for this employee (employee > team > company),
                // then run the attendance engine (tiered / net-hours / simple + break deduction).
                $team = property_exists($e, 'team') ? $e->team : null;
                $pol = $polRows->isEmpty() ? null : $this->resolvePolicy($polRows, $e->emp_code, $team);
                if ($pol) {
                    $stats = $this->dayStats($e->emp_code, $month, $endDate, $tid);
                    // rev173 — per-day shift resolution (roster > employee default).
                    $dayShifts = [];
                    if ($shiftDefs) {
                        $empShift = property_exists($e, 'shift') ? $e->shift : null;
                        foreach (array_keys($stats) as $sd) {
                            $dayShifts[$sd] = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $e->name, $empShift, $sd);
                        }
                    }
                    $res = $this->attendanceCut($stats, $pol, $dayShifts);
                    $lateCut = $res['lateCut'];
                    $breakCut = $res['breakCut'];
                    $lateDays = $res['late'];
                }
                // Paid leave counts as present; an employee with zero punches is
                // paid in full (safeguard for companies not using biometric).
                $paidDays = max(0.0, min($empWorking, $present + $leave) - $lateCut - $breakCut);
                $factor = min(1.0, $paidDays / $empWorking); // rev172 (M1) — DOJ-aware denominator; factor never exceeds full month
            }
            $teamC = property_exists($e, 'team') ? $e->team : null;
            $salComps = $this->resolveComponents($compRows, $e->emp_code, $teamC);
            $s = $salComps->isNotEmpty()
                ? (AppDataController::computeSlipFromComponents($ctc * $factor, $salComps, $rates, (string) ($e->employment_stage ?? '')) ?: AppDataController::computeSlip($ctc * $factor, $rates, (string) ($e->employment_stage ?? '')))
                : AppDataController::computeSlip($ctc * $factor, $rates, (string) ($e->employment_stage ?? ''));
            $commission = (float) ($commByEmp[$e->id] ?? 0.0);
            $commRows = $commRowsByEmp[$e->id] ?? [];
            // rev165 — split commission entries by Purpose so incentive and
            // commission show as SEPARATE variable lines (sum still = $commission).
            $commMap = [];
            foreach ($commRows as $cr) {
                $pu = trim((string) ($cr['purpose'] ?? 'Commission')) ?: 'Commission';
                $commMap[$pu] = round(($commMap[$pu] ?? 0) + (float) ($cr['amount'] ?? 0), 2);
            }
            // rev173 — Night Shift Allowance: Rs per night ACTUALLY worked. A night
            // = a distinct punch date whose resolved shift (roster > employee
            // default) is a night shift with an allowance set, and the roster
            // doesn't mark it a week-off. Independent of the Late Policy.
            $nightAmt = 0.0;
            $nightDays = 0;
            if ($anyNight) {
                $empShiftN = property_exists($e, 'shift') ? $e->shift : null;
                foreach ($this->presentDates($e->emp_code, $month, $endDate, $tid) as $nd) {
                    $shN = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $e->name, $empShiftN, $nd);
                    if ($shN && empty($shN['off']) && $shN['night'] && $shN['allowance'] > 0) {
                        $nightAmt += $shN['allowance'];
                        $nightDays++;
                    }
                }
                $nightAmt = round($nightAmt, 2);
            }

            $gross = round($s['gross'] + $commission + $nightAmt, 2);
            $net = round($s['net'] + $commission + $nightAmt, 2);
            $note = $this->calcNote($ctc, $s, (bool) $lop, $present, $leave, $working, $factor, $lateDays, $lateCut, $breakCut, $pol, $commission, array_column($commRows, 'label'));
            if ($nightAmt > 0) {
                $note .= ' Night shift allowance: '.$nightDays.' night(s) worked -> Rs '.number_format($nightAmt, 0).' added.';
            }
            $tg += $gross;
            $td += $s['total_ded'];
            $tn += $net;
            $rows[] = [
                'employee_id' => (int) $e->id,
                'code' => $e->emp_code,
                'name' => $e->name,
                'presentDays' => $lop ? $present : null,
                'leaveDays' => $lop ? round($leave, 1) : null,
                'lateDays' => $lop ? $lateDays : null,
                'lateCut' => $lop ? round($lateCut, 2) : null,
                'breakCut' => $lop ? round($breakCut, 2) : null,
                'commission' => round($commission, 2),
                'commissionMap' => $commMap,
                'commissionIds' => array_column($commRows, 'id'),
                'nightAllowance' => $nightAmt,   // rev173
                'nightDays' => $nightDays,       // rev173
                'note' => $note,
                'factor' => round($factor, 4),
                'earnings' => $s['earnings'] ?? null,
                'deductionsMap' => $s['deductions'] ?? null,
                'basic' => $s['basic'],
                'hra' => $s['hra'],
                'special' => $s['special'],
                'conveyance' => $s['conveyance'] ?? 0,
                'pf' => $s['pf'],
                'esi' => $s['esi'],
                'pt' => $s['pt'],
                'tds' => $s['tds'],
                'gross' => $gross,
                'ded' => $s['total_ded'],
                'net' => $net,
            ];
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'totals' => [
                'count' => count($rows),
                'gross' => round($tg, 2),
                'ded' => round($td, 2),
                'net' => round($tn, 2),
            ],
            'meta' => [
                'daysInMonth' => $daysInMonth,
                'workingDays' => $working,
                'holidays' => count($holidays),
                'monthLabel' => $start->format('M Y'),
                'payDate' => $endDate,
            ],
        ];
    }

    /**
     * LIVE SALARY (rev 79, Ejaz 4 Jun 2026) — one employee's running-month
     * salary EARNED TILL TODAY, using the SAME engine as payroll generation
     * (attendance/leave/late-policy/components/statutory), just capped at
     * today's date. Plus this month's approved extras: commissions (+),
     * expense reimbursements (+), loan EMIs (−), advances approved (−).
     *
     * Visibility (strict hierarchy): a logged-in employee sees ONLY their own
     * panel; a reporting manager / team leader additionally gets a dropdown of
     * their direct reportees; Admin / HR / Super Admin see every employee.
     */
    public function liveSalary(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id;
            $isHr = $user->hasAnyRole(['super_admin', 'admin', 'hr_manager']);

            // Viewer's own employee record (users.employee_id, else email/name).
            $me = null;
            if (! empty($user->employee_id)) {
                $me = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            }
            if (! $me) {
                $me = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
                    ->first();
            }

            // Scope: who may this viewer look at?
            $base = DB::table('employees')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereNull('deleted_at')->where('status', 'active');
            if ($isHr) {
                $scope = $base->orderBy('emp_code')->get();
            } elseif ($me) {
                $scope = $base->where(function ($q) use ($me) {
                    $q->where('id', $me->id)
                        ->orWhere('reporting_manager', $me->name)
                        ->orWhere('team_leader', $me->name);
                    if (Schema::hasColumn('employees', 'reporting_manager_id')) {
                        $q->orWhere('reporting_manager_id', $me->id);
                    }
                })->orderBy('emp_code')->get();
            } else {
                return response()->json(['ok' => false, 'error' => 'Your login is not linked to an employee record — ask HR to link it in User Access.'], 422);
            }
            if ($scope->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'No employees in your view.'], 422);
            }

            // Target: requested (must be inside the scope) | own record | first in scope.
            $reqId = (int) $request->query('employee_id', 0);
            $target = $reqId ? $scope->firstWhere('id', $reqId) : null;
            if ($reqId && ! $target) {
                return response()->json(['ok' => false, 'error' => 'That employee is not in your view.'], 403);
            }
            if (! $target) {
                $target = ($me ? $scope->firstWhere('id', $me->id) : null) ?: $scope->first();
            }

            $company = DB::table('companies')->where('id', $target->company_id)->first();
            if (! $company) {
                return response()->json(['ok' => false, 'error' => 'Employee has no company set.'], 422);
            }
            $ctc = (float) $target->ctc;
            if ($ctc <= 0) {
                return response()->json(['ok' => false, 'error' => 'No CTC set for '.$target->name.' — set it in their employee profile first.'], 422);
            }

            $rates = SettingsController::rates($tid);
            $month = now()->format('Y-m');
            [$start, $daysInMonth, , ] = $this->monthMeta($month);
            $today = now()->toDateString();
            $dayOfMonth = (int) now()->day;

            // Working days: full month + elapsed-till-today (Sundays/holidays excluded).
            $holidays = $this->holidayDates($tid, $month, $start->copy()->endOfMonth()->toDateString(), $rates);
            $working = 0;
            $workingSoFar = 0;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $cur = Carbon::create($start->year, $start->month, $d);
                if (self::isWeekOff($cur, $rates) || isset($holidays[$cur->toDateString()])) {
                    continue;
                }
                $working++;
                if ($d <= $dayOfMonth) {
                    $workingSoFar++;
                }
            }
            $working = max(1, $working);

            // Attendance till TODAY (same helpers as payroll, endDate = today).
            $present = $this->presentDays($target->emp_code, $month, $today, $tid);
            $leave = $present > 0 ? $this->paidLeaveDays((int) $target->id, $tid, $month, $today, $holidays, $rates) : 0.0;
            $lateCut = 0.0;
            $breakCut = 0.0;
            $lateDays = 0;
            $team = property_exists($target, 'team') ? $target->team : null;
            $polRows = $this->latePolicyRows($company, $tid);
            $pol = $polRows->isEmpty() ? null : $this->resolvePolicy($polRows, $target->emp_code, $team);
            // rev173 — same shift resolution as payroll (roster > employee default).
            $shiftDefs = \App\Services\ShiftResolver::shifts($tid);
            $rosterMap = $shiftDefs ? \App\Services\ShiftResolver::rosterMap($tid, $month.'-01', $today) : [];
            $empShiftLv = property_exists($target, 'shift') ? $target->shift : null;
            if ($pol && $present > 0) {
                $stats = $this->dayStats($target->emp_code, $month, $today, $tid);
                $dayShifts = [];
                if ($shiftDefs) {
                    foreach (array_keys($stats) as $sd) {
                        $dayShifts[$sd] = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $target->name, $empShiftLv, $sd);
                    }
                }
                $res = $this->attendanceCut($stats, $pol, $dayShifts);
                $lateCut = $res['lateCut'];
                $breakCut = $res['breakCut'];
                $lateDays = $res['late'];
            }
            // No punches at all → pro-rate by elapsed working days (companies
            // without biometric still get a sensible live figure).
            $paidSoFar = $present > 0
                ? max(0.0, min($working, $present + $leave) - $lateCut - $breakCut)
                : (float) $workingSoFar;
            $factor = min(1.0, $paidSoFar / $working);

            // Components — identical resolution to payroll (employee > team > company).
            $compRows = $this->componentRows($company, $tid);
            $salComps = $this->resolveComponents($compRows, $target->emp_code, $team);
            $sFull = $salComps->isNotEmpty()
                ? (AppDataController::computeSlipFromComponents($ctc, $salComps, $rates, (string) ($target->employment_stage ?? '')) ?: AppDataController::computeSlip($ctc, $rates, (string) ($target->employment_stage ?? '')))
                : AppDataController::computeSlip($ctc, $rates, (string) ($target->employment_stage ?? ''));
            $sNow = $salComps->isNotEmpty()
                ? (AppDataController::computeSlipFromComponents($ctc * $factor, $salComps, $rates) ?: AppDataController::computeSlip($ctc * $factor, $rates))
                : AppDataController::computeSlip($ctc * $factor, $rates);

            // Earnings / deductions component lists (named maps when available).
            $earnings = [];
            foreach (($sNow['earnings'] ?? null) ?: ['Basic' => $sNow['basic'], 'HRA' => $sNow['hra'], 'Special / other allowances' => $sNow['special']] as $label => $amt) {
                if ((float) $amt > 0) {
                    $earnings[] = ['label' => $label, 'amount' => round((float) $amt, 2)];
                }
            }
            $deductions = [];
            foreach (($sNow['deductions'] ?? null) ?: ['PF (employee)' => $sNow['pf'], 'ESI' => $sNow['esi'], 'Professional tax' => $sNow['pt'], 'TDS' => $sNow['tds']] as $label => $amt) {
                if ((float) $amt > 0) {
                    $deductions[] = ['label' => $label, 'amount' => round((float) $amt, 2)];
                }
            }

            // This month's APPROVED extras (payout-date driven, rev 84).
            $commData = $this->commissionByEmployee($company, $tid, $month);
            $commission = (float) ($commData['sum'][$target->id] ?? 0.0);
            if ($commission > 0) {
                $earnings[] = ['label' => 'Commission / incentive (approved, due this month)', 'amount' => round($commission, 2)];
            }
            // rev173 — night shift allowance earned so far this month.
            $nightAmtLv = 0.0;
            if ($shiftDefs) {
                foreach ($this->presentDates($target->emp_code, $month, $today, $tid) as $nd) {
                    $shN = \App\Services\ShiftResolver::resolve($shiftDefs, $rosterMap, (string) $target->name, $empShiftLv, $nd);
                    if ($shN && empty($shN['off']) && $shN['night'] && $shN['allowance'] > 0) {
                        $nightAmtLv += $shN['allowance'];
                    }
                }
                $nightAmtLv = round($nightAmtLv, 2);
                if ($nightAmtLv > 0) {
                    $earnings[] = ['label' => 'Night shift allowance (nights worked)', 'amount' => $nightAmtLv];
                }
            }
            $monthStart = $month.'-01 00:00:00';
            $reimb = 0.0;
            try {
                if (Schema::hasTable('expenses')) {
                    $reimb = (float) DB::table('expenses')->where('employee_id', $target->id)->where('status', 'approved')
                        ->where('created_at', '>=', $monthStart)->sum('amount');
                }
            } catch (\Throwable $e) {
            }
            if ($reimb > 0) {
                $earnings[] = ['label' => 'Expense reimbursements (approved)', 'amount' => round($reimb, 2)];
            }
            $emi = 0.0;
            try {
                if (Schema::hasTable('loans')) {
                    // rev172 (H1) — only ACTIVE loans that still have installments
                    // remaining contribute EMI. Prevents deducting after a loan is
                    // fully repaid. Adapts to whichever repayment columns exist
                    // (installments_paid/total, or outstanding).
                    $emi = (float) DB::table('loans')->where('employee_id', $target->id)
                        ->whereIn('status', ['approved', 'active'])
                        ->when(
                            Schema::hasColumn('loans', 'installments_paid') && Schema::hasColumn('loans', 'installments_total'),
                            fn ($q) => $q->whereColumn('installments_paid', '<', 'installments_total')
                        )
                        ->when(
                            Schema::hasColumn('loans', 'outstanding'),
                            fn ($q) => $q->where('outstanding', '>', 0)
                        )
                        ->sum('emi');
                }
            } catch (\Throwable $e) {
            }
            if ($emi > 0) {
                $deductions[] = ['label' => 'Loan EMI', 'amount' => round($emi, 2)];
            }
            $adv = 0.0;
            try {
                if (Schema::hasTable('advances')) {
                    $adv = (float) DB::table('advances')->where('employee_id', $target->id)->where('status', 'approved')
                        ->where('created_at', '>=', $monthStart)->sum('amount');
                }
            } catch (\Throwable $e) {
            }
            if ($adv > 0) {
                $deductions[] = ['label' => 'Salary advance recovery (this month)', 'amount' => round($adv, 2)];
            }

            // ---- "How you earned it" LOG (rev 79b, Ejaz) — passbook-style
            // entries: every credit/debit with heading, detail, date, status.
            // status: included (in the net) | pending (awaiting approval —
            // visible but NOT counted) | info (explains money NOT earned).
            $entries = [];
            $dayValue = round(((float) $sFull['gross']) / $working, 2);
            $entries[] = ['date' => now()->format('d M'), 'sign' => '+',
                'head' => 'Salary earned till today',
                'detail' => round($paidSoFar, 1).' paid day(s) of '.$working.' working days — basic + allowances as per your salary structure',
                'amount' => round((float) $sNow['gross'], 2), 'status' => 'included'];
            if (($lateCut + $breakCut) > 0) {
                $entries[] = ['date' => now()->format('d M'), 'sign' => '-',
                    'head' => 'Late penalty (late policy)',
                    'detail' => $lateDays.' late mark(s) → '.round($lateCut + $breakCut, 2).' day(s) cut',
                    'amount' => round($dayValue * ($lateCut + $breakCut), 2), 'status' => 'info'];
            }
            if ($present > 0) {
                $missed = max(0.0, $workingSoFar - ($present + $leave));
                if ($missed > 0.5) {
                    $entries[] = ['date' => now()->format('d M'), 'sign' => '-',
                        'head' => 'Not earned — absent',
                        'detail' => round($missed, 1).' working day(s) without attendance so far this month',
                        'amount' => round($dayValue * $missed, 2), 'status' => 'info'];
                }
            }
            // Commission / incentive entries — entry-wise. rev 84 (Ejaz):
            // in-month test is PAYOUT-DATE first (matches the payslip rule);
            // pending ones are totalled separately for the PROJECTED figure;
            // and EVERY entry (any month) feeds the new "Earnings" tab.
            $pendingComm = 0.0;
            $commList = [];
            try {
                if (Schema::hasTable('commissions')) {
                    $cSel = ['id', 'amount', 'status', 'created_at'];
                    foreach (['portfolio', 'cycle_month', 'payout_date', 'payout_method', 'locked_at', 'lock_source', 'type', 'kind', 'decided_by', 'reason',
                        'purpose', 'description', 'gross_amount', 'tds_rate', 'tds_amount', 'entered_by'] as $cc) {
                        if (Schema::hasColumn('commissions', $cc)) {
                            $cSel[] = $cc;
                        }
                    }
                    $cRows = DB::table('commissions')->where('employee_id', $target->id)
                        ->whereIn('status', ['approved', 'pending', 'rejected'])
                        ->orderByDesc('id')->limit(120)->get($cSel);
                    foreach ($cRows as $cr) {
                        $payout = trim((string) ($cr->payout_date ?? ''));
                        $cm = trim((string) ($cr->cycle_month ?? ''));
                        // Earnings tab: every entry, any month, any status.
                        $commList[] = [
                            'id' => (int) $cr->id,
                            'date' => substr((string) $cr->created_at, 0, 10),
                            'purpose' => (string) (($cr->purpose ?? '') ?: (($cr->kind ?? '') ?: ($cr->type ?? ''))),
                            'portfolio' => (string) ($cr->portfolio ?? ''),
                            'earnedMonth' => $cm,
                            'payoutDate' => $payout !== '' ? substr($payout, 0, 10) : '',
                            'payoutMethod' => (string) ((($cr->payout_method ?? '') ?: 'with_salary')),
                            'gross' => round((float) ($cr->gross_amount ?? 0), 2),
                            'tds' => round((float) ($cr->tds_amount ?? 0), 2),
                            'net' => round((float) $cr->amount, 2),
                            'paid' => 0,        // filled below from the ledger
                            'balance' => round((float) $cr->amount, 2),
                            'status' => (string) $cr->status,
                            'locked' => ! empty($cr->locked_at),
                            'lockSource' => (string) ($cr->lock_source ?? ''),
                            'decidedBy' => (string) ($cr->decided_by ?? ''),
                            'description' => (string) ($cr->description ?? ''),
                        ];
                        if ($cr->status === 'rejected') {
                            continue;   // visible in the tab, never in the panel
                        }
                        // In-month test for the salary panel (payslip rule).
                        if ($payout !== '') {
                            $inMonth = substr($payout, 0, 7) === $month;
                        } elseif ($cm !== '') {
                            try {
                                $inMonth = Carbon::parse($cm)->format('Y-m') === $month;
                            } catch (\Throwable $e) {
                                $inMonth = false;
                            }
                        } else {
                            $inMonth = substr((string) $cr->created_at, 0, 7) === $month;
                        }
                        if (! $inMonth) {
                            continue;
                        }
                        if ($cr->status === 'pending') {
                            $pendingComm += (float) $cr->amount;
                        }
                        $bits = array_filter([
                            ($cr->purpose ?? null) ?: (($cr->kind ?? null) ?: ($cr->type ?? null)),
                            ! empty($cr->portfolio) ? 'portfolio '.$cr->portfolio : null,
                            ! empty($cr->gross_amount) ? 'gross ₹'.number_format((float) $cr->gross_amount, 2).' − TDS ₹'.number_format((float) ($cr->tds_amount ?? 0), 2) : null,
                            $payout !== '' ? 'payout '.substr($payout, 0, 10) : null,
                            ($cr->description ?? null) ?: null,
                            ! empty($cr->entered_by) ? 'entered by '.$cr->entered_by : null,
                            ($cr->status === 'approved' && ! empty($cr->decided_by)) ? 'approved by '.$cr->decided_by : null,
                            $cr->status === 'pending' ? 'awaiting approval' : null,
                            ! empty($cr->locked_at) ? 'LOCKED' : null,
                        ]);
                        $entries[] = ['date' => substr((string) $cr->created_at, 8, 2).' '.Carbon::parse($cr->created_at)->format('M'), 'sign' => '+',
                            'head' => 'Commission / incentive',
                            'detail' => implode(' · ', $bits) ?: 'commission entry',
                            'amount' => round((float) $cr->amount, 2),
                            'status' => $cr->status === 'approved' ? 'included' : 'pending'];
                    }
                    // rev 85: overlay paid/balance from the disbursement ledger.
                    if ($commList && Schema::hasTable('commission_payments')) {
                        try {
                            $payMap = [];
                            foreach (DB::table('commission_payments')->whereIn('commission_id', array_column($commList, 'id'))
                                ->groupBy('commission_id')->selectRaw('commission_id, SUM(amount) s')->get() as $pm) {
                                $payMap[(int) $pm->commission_id] = (float) $pm->s;
                            }
                            foreach ($commList as &$cl) {
                                $cl['paid'] = round($payMap[$cl['id']] ?? 0, 2);
                                $cl['balance'] = $cl['status'] === 'approved' ? round($cl['net'] - $cl['paid'], 2) : $cl['balance'];
                            }
                            unset($cl);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            // Approved expense reimbursements this month — entry-wise.
            try {
                if (Schema::hasTable('expenses')) {
                    $eSel = ['amount', 'created_at'];
                    foreach (['type', 'reason', 'decided_by'] as $ec) {
                        if (Schema::hasColumn('expenses', $ec)) {
                            $eSel[] = $ec;
                        }
                    }
                    foreach (DB::table('expenses')->where('employee_id', $target->id)->where('status', 'approved')
                        ->where('created_at', '>=', $monthStart)->orderByDesc('id')->limit(30)->get($eSel) as $er) {
                        $entries[] = ['date' => Carbon::parse($er->created_at)->format('d M'), 'sign' => '+',
                            'head' => 'Expense reimbursement',
                            'detail' => implode(' · ', array_filter([$er->type ?? null, $er->reason ?? null, ! empty($er->decided_by) ? 'approved by '.$er->decided_by : null])) ?: 'approved claim',
                            'amount' => round((float) $er->amount, 2), 'status' => 'included'];
                    }
                }
            } catch (\Throwable $e) {
            }
            if ($emi > 0) {
                $entries[] = ['date' => now()->format('d M'), 'sign' => '-',
                    'head' => 'Loan EMI', 'detail' => 'monthly instalment on your approved loan(s)',
                    'amount' => round($emi, 2), 'status' => 'included'];
            }
            if ($adv > 0) {
                $entries[] = ['date' => now()->format('d M'), 'sign' => '-',
                    'head' => 'Salary advance recovery', 'detail' => 'advance approved this month, recovered from salary',
                    'amount' => round($adv, 2), 'status' => 'included'];
            }

            $sumE = 0.0;
            foreach ($earnings as $x) {
                $sumE += $x['amount'];
            }
            $sumD = 0.0;
            foreach ($deductions as $x) {
                $sumD += $x['amount'];
            }

            return response()->json([
                'ok' => true,
                'employee' => ['id' => $target->id, 'code' => $target->emp_code, 'name' => $target->name,
                    'company' => $company->name ?? '', 'designation' => $target->designation ?? '', 'ctc' => $ctc],
                'meta' => [
                    'monthLabel' => $start->format('F Y'), 'today' => now()->format('d M Y'),
                    'daysInMonth' => $daysInMonth, 'workingDays' => $working, 'workingSoFar' => $workingSoFar,
                    'presentDays' => $present, 'paidLeaveDays' => round($leave, 1), 'lateDays' => $lateDays,
                    'attendanceTracked' => $present > 0,
                    'factorPct' => round($factor * 100, 1),
                    'fullMonthGross' => round((float) $sFull['gross'], 2),
                    'fullMonthNet' => round((float) $sFull['net'], 2),
                ],
                'earnings' => $earnings,
                'deductions' => $deductions,
                'entries' => $entries,
                'gross' => round($sumE, 2),
                'totalDeductions' => round($sumD, 2),
                'net' => round($sumE - $sumD, 2),
                // rev 84 (Ejaz): TWO totals — certain vs awaiting approval.
                'pendingCommission' => round($pendingComm, 2),
                'projectedNet' => round($sumE - $sumD + $pendingComm, 2),
                'commList' => $commList,
                // rev 115: live incentive schemes for THIS employee — the Live
                // Salary card shows them as the orange "earn more" ribbon.
                'schemes' => $this->liveSchemesFor($target),
                'canPick' => $scope->count() > 1,
                'employees' => $scope->count() > 1
                    ? $scope->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'code' => $x->emp_code])->values()
                    : [],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** rev 115: active schemes applicable to an employee (fail-soft, max 5). */
    private function liveSchemesFor(object $emp): array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('incentive_schemes')) {
                return [];
            }
            $today = now()->toDateString();

            return DB::table('incentive_schemes')
                ->when($emp->tenant_id ?? null, fn ($q) => $q->where('tenant_id', $emp->tenant_id))
                ->where('status', 'active')
                ->where(function ($q) use ($today) {
                    $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('valid_till')->orWhere('valid_till', '>=', $today);
                })
                ->orderByDesc('id')->limit(25)->get()
                ->filter(fn ($s) => \App\Http\Controllers\SchemeController::appliesTo($s, $emp))
                ->take(5)
                ->map(fn ($s) => [
                    'id' => $s->id, 'title' => $s->title,
                    'rate' => $s->rate_type === 'percent'
                        ? rtrim(rtrim(number_format((float) $s->rate_value, 2), '0'), '.').'% of collections'
                        : ($s->rate_type === 'fixed' ? '₹'.number_format((float) $s->rate_value).' per claim' : 'open amount'),
                    'till' => $s->valid_till ? \Carbon\Carbon::parse($s->valid_till)->format('d M') : null,
                ])->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Existing run (if any) for this tenant/company/month. */
    private function existingRun(Request $request, object $company, string $month): ?object
    {
        $tid = $request->user()->tenant_id;

        return DB::table('payroll_runs')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->where('company_id', $company->id)
            ->where('cycle_label', $month)
            ->orderByDesc('id')
            ->first();
    }

    /** PREVIEW — compute the run without writing anything. */
    public function preview(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $month = $this->normMonth($request->query('month'));
            if (! $month) {
                return response()->json(['ok' => false, 'error' => 'Pick a valid month.'], 422);
            }
            $company = $this->resolveCompany($request, $request->query('company_id'));
            if (! $company) {
                return response()->json(['ok' => false, 'error' => 'Pick a company you manage.'], 422);
            }
            $lop = filter_var($request->query('lop'), FILTER_VALIDATE_BOOLEAN);
            $c = $this->compute($request, $company, $month, $lop);

            $existing = $this->existingRun($request, $company, $month);

            return response()->json([
                'ok' => true,
                'company' => $company->name,
                'companyId' => (int) $company->id,
                'month' => $month,
                'monthLabel' => $c['meta']['monthLabel'],
                'lop' => $lop,
                'daysInMonth' => $c['meta']['daysInMonth'],
                'workingDays' => $c['meta']['workingDays'],
                'holidays' => $c['meta']['holidays'],
                'rows' => $c['rows'],
                'totals' => $c['totals'],
                'skipped' => $c['skipped'],
                'exists' => (bool) $existing,
                'existingStatus' => $existing->status ?? null,
                'existingRunId' => $existing->id ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** GENERATE — create the draft payroll_run + payslips. */
    public function generate(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $v = $request->validate([
                'company_id' => ['required'],
                'month' => ['required', 'string'],
                'lop' => ['nullable'],
                'regenerate' => ['nullable'],
            ]);
            $month = $this->normMonth($v['month']);
            if (! $month) {
                return response()->json(['ok' => false, 'error' => 'Pick a valid month.'], 422);
            }
            $company = $this->resolveCompany($request, $v['company_id']);
            if (! $company) {
                return response()->json(['ok' => false, 'error' => 'Pick a company you manage.'], 422);
            }
            $lop = filter_var($request->input('lop'), FILTER_VALIDATE_BOOLEAN);
            $regenerate = filter_var($request->input('regenerate'), FILTER_VALIDATE_BOOLEAN);
            $tid = $request->user()->tenant_id;

            // Idempotency: one run per company/month. A run already past draft is
            // locked from regeneration; a draft can be replaced only on request.
            $existing = $this->existingRun($request, $company, $month);
            if ($existing) {
                $locked = in_array($existing->status, ['hr_approved', 'approved', 'locked', 'paid'], true);
                if ($locked) {
                    return response()->json(['ok' => false, 'error' => 'A '.$existing->status.' run already exists for '.$month.'. It can no longer be regenerated.'], 409);
                }
                if (! $regenerate) {
                    return response()->json(['ok' => false, 'needsConfirm' => true, 'error' => 'A draft run already exists for '.$month.'. Regenerate to replace it.'], 409);
                }
                // rev172 (M2) — record every draft regeneration so a silently
                // changed payslip always has a trail (who/when/which run replaced).
                try {
                    if (Schema::hasTable('activity_logs')) {
                        $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
                        DB::table('activity_logs')->insert(ApprovalService::safeRow('activity_logs', [
                            'tenant_id' => $tid,
                            'user_id' => optional($request->user())->id,
                            'action' => 'payroll.regenerate',
                            'entity' => 'payroll_runs',
                            'entity_id' => $existing->id,
                            'detail' => json_encode(['by' => $by, 'company' => $company->name, 'month' => $month, 'replaced_run' => $existing->id]),
                            'ip' => $request->ip(),
                            'created_at' => now(),
                        ]));
                    }
                } catch (\Throwable $e) {
                    // audit is best-effort; never block the regenerate on it
                }
                // Replace the existing draft: drop its payslips then the run.
                DB::table('payslips')->where('run_id', $existing->id)->delete();
                DB::table('payroll_runs')->where('id', $existing->id)->delete();
                // rev165 DATA INTEGRITY: also reverse the COMMISSION side of the old
                // run. The first generate inserted a commission_payments debit
                // (reference "run #<id> · …") and set commissions.locked_at; if we
                // don't undo them, regenerating leaves a passbook debit pointing at a
                // deleted run + a lock for a run that no longer exists, and the
                // recompute's whereNull('locked_at') skips those commissions while
                // they still fold into the new payslip. Best-effort; never blocks.
                try {
                    if (Schema::hasTable('commission_payments')) {
                        $refLike = 'run #'.$existing->id.' %';
                        $freedCids = DB::table('commission_payments')
                            ->where('mode', 'payslip')->where('reference', 'like', $refLike)
                            ->pluck('commission_id')->all();
                        DB::table('commission_payments')
                            ->where('mode', 'payslip')->where('reference', 'like', $refLike)->delete();
                        if ($freedCids && Schema::hasColumn('commissions', 'locked_at')) {
                            $clear = ['locked_at' => null, 'updated_at' => now()];
                            if (Schema::hasColumn('commissions', 'locked_by')) {
                                $clear['locked_by'] = null;
                            }
                            if (Schema::hasColumn('commissions', 'lock_source')) {
                                $clear['lock_source'] = null;
                            }
                            DB::table('commissions')->whereIn('id', $freedCids)->update($clear);
                        }
                    }
                } catch (\Throwable $e) {
                    // best-effort; a regenerate must not fail on the reversal.
                }
            }

            $c = $this->compute($request, $company, $month, $lop);
            if (empty($c['rows'])) {
                return response()->json(['ok' => false, 'error' => 'No active employees with a CTC found for this company. '.($c['skipped'] ? $c['skipped'].' employee(s) have no CTC set.' : '')], 422);
            }

            [, , , $payDate] = $this->monthMeta($month);

            $runRow = ApprovalService::safeRow('payroll_runs', [
                'tenant_id' => $tid,
                'company_id' => $company->id,
                'lot' => 1,
                'cycle_label' => $month,
                'pay_date' => $payDate,
                'status' => 'draft',
                'employees_count' => $c['totals']['count'],
                'net_total' => $c['totals']['net'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // rev172 (H2) — ensure the calc_note column BEFORE opening the
            // transaction: Schema::table (DDL) implicitly commits in MySQL, so it
            // must not sit inside the atomic block below.
            if (! Schema::hasColumn('payslips', 'calc_note')) {
                try {
                    Schema::table('payslips', function (Blueprint $t) {
                        $t->text('calc_note')->nullable();
                    });
                } catch (\Throwable $e) {
                    // ignore — note simply won't persist if the column can't be added
                }
            }

            // rev172 (H2) — write the run header + every payslip ATOMICALLY, so a
            // failure mid-way can never leave a run with a partial set of payslips.
            DB::beginTransaction();
            try {
                $runId = DB::table('payroll_runs')->insertGetId($runRow);

                foreach ($c['rows'] as $r) {
                $earnings = ! empty($r['earnings'])
                    ? $r['earnings']
                    : ['Basic' => $r['basic'], 'HRA' => $r['hra'], 'Special Allowance' => $r['special']];
                if (! empty($r['commissionMap'])) {
                    // Each commission Purpose becomes its own variable earning line
                    // (e.g. Recovery Commission, Collection Incentive).
                    foreach ($r['commissionMap'] as $cpurp => $camt) {
                        if ((float) $camt == 0.0) {
                            continue;
                        }
                        $earnings[$cpurp] = round(($earnings[$cpurp] ?? 0) + (float) $camt, 2);
                    }
                } elseif (! empty($r['commission'])) {
                    $earnings['Commission'] = $r['commission'];
                }
                if (! empty($r['nightAllowance'])) {
                    // rev173 — night shift allowance as its own earning line.
                    $earnings['Night Shift Allowance'] = round(($earnings['Night Shift Allowance'] ?? 0) + (float) $r['nightAllowance'], 2);
                }
                $deductions = ! empty($r['deductionsMap'])
                    ? $r['deductionsMap']
                    : ['PF' => $r['pf'], 'ESI' => $r['esi'], 'Professional Tax' => $r['pt'], 'TDS' => $r['tds']];
                if (empty($r['deductionsMap']) && ! empty($r['conveyance'])) {
                    $deductions['Conveyance'] = $r['conveyance'];
                }
                $slip = ApprovalService::safeRow('payslips', [
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tid,
                    'company_id' => $company->id,
                    'employee_id' => $r['employee_id'],
                    'run_id' => $runId,
                    'month' => $month,
                    'earnings' => json_encode($earnings),
                    'deductions' => json_encode($deductions),
                    'gross' => $r['gross'],
                    'total_ded' => $r['ded'],
                    'net' => $r['net'],
                    'calc_note' => $r['note'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('payslips')->insert($slip);
                }
                DB::commit();
            } catch (\Throwable $eTx) {
                DB::rollBack();
                throw $eTx; // surfaced by the method-level catch as a clean JSON error
            }

            // rev 84 (Ejaz): the LOCK — every commission included in this run
            // is frozen forever (no edit, no re-decision). Logged per entry.
            try {
                $lockIds = [];
                foreach ($c['rows'] as $r) {
                    foreach ((array) ($r['commissionIds'] ?? []) as $cid) {
                        $lockIds[] = (int) $cid;
                    }
                }
                if ($lockIds && Schema::hasColumn('commissions', 'locked_at')) {
                    $by = trim((string) ($request->user()->name ?? '')) ?: (string) $request->user()->email;
                    $lockRows = DB::table('commissions')->whereIn('id', $lockIds)->whereNull('locked_at')
                        ->get(['id', 'employee_id', 'amount', 'tenant_id']);
                    $toLock = $lockRows->pluck('id');
                    DB::table('commissions')->whereIn('id', $toLock)->update([
                        'locked_at' => now(), 'locked_by' => $by,
                        'lock_source' => 'payslip '.$c['meta']['monthLabel'], 'updated_at' => now(),
                    ]);
                    foreach ($lockRows as $lr) {
                        ApprovalService::logCommission($tid, (int) $lr->id, 'payslip',
                            'Included in '.$c['meta']['monthLabel'].' payroll (run #'.$runId.') and LOCKED', $by);
                        // rev 85: the ledger records the payslip as the payment
                        // (debit), so each employee's passbook is complete.
                        try {
                            if (Schema::hasTable('commission_payments')) {
                                DB::table('commission_payments')->insert([
                                    'tenant_id' => $lr->tenant_id,
                                    'commission_id' => (int) $lr->id,
                                    'employee_id' => $lr->employee_id,
                                    'paid_on' => now()->toDateString(),
                                    'amount' => round((float) $lr->amount, 2),
                                    'mode' => 'payslip',
                                    'reference' => 'run #'.$runId.' · '.$c['meta']['monthLabel'],
                                    'note' => null,
                                    'by' => $by,
                                    'created_at' => now(),
                                ]);
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('Commission payslip ledger debit failed (#'.$lr->id.'): '.$e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Commission lock on payroll generate failed: '.$e->getMessage());
            }

            return response()->json([
                'ok' => true,
                'runId' => $runId,
                'count' => $c['totals']['count'],
                'net' => $c['totals']['net'],
                'skipped' => $c['skipped'],
                'message' => 'Draft payroll for '.$c['meta']['monthLabel'].' created — '.$c['totals']['count'].' employee(s), net ₹'.number_format($c['totals']['net'], 2).'. Now in Salary Approval.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}