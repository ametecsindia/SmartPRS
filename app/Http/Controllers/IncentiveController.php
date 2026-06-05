<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission / Incentive calculation engine (bulk).
 *
 * Computes per-employee payouts from a chosen BASIS and FORMULA, supports bulk
 * CSV import of the per-employee figures, previews the result, and bulk-creates
 * commission entries that feed payroll (same store the single-entry screen uses).
 *
 * BASIS:    collected (amount recovered) | target (gate on % of target met) | manual (amount is the payout)
 * FORMULA:  flat (% of figure) | slab (whole-amount band rate) | portfolio (rate per portfolio/bank)
 *
 * Incentives for salaried staff use the same engine (type = incentive); the
 * created entries are tagged to the month and, once approved, fold into payroll.
 * Money math mirrors the Python-verified model. Admin/HR guarded, fail-soft.
 */
class IncentiveController extends Controller
{
    public function template()
    {
        $csv = "emp_code,employee,portfolio,collected,target,amount\n"
            ."EMP100,Sample Name,ICICI,150000,100000,0\n"
            ."EMP101,Another Name,HDFC,600000,800000,0\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="commission-import-template.csv"',
        ]);
    }

    /** Pure compute — returns the preview rows + total. No writes. */
    public function calculate(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $cfg = $this->cfg($request);
            $rows = $request->input('rows', []);
            if (! is_array($rows)) {
                $rows = [];
            }
            $out = [];
            $total = 0.0;
            foreach ($rows as $r) {
                [$payout, $ach] = $this->payoutFor((array) $r, $cfg);
                $total += $payout;
                $out[] = [
                    'emp_code' => $r['emp_code'] ?? '',
                    'employee' => $r['employee'] ?? '',
                    'portfolio' => $r['portfolio'] ?? '',
                    'collected' => (float) ($r['collected'] ?? 0),
                    'target' => (float) ($r['target'] ?? 0),
                    'achievement' => $ach,
                    'payout' => $payout,
                ];
            }

            return response()->json(['ok' => true, 'rows' => $out, 'total' => round($total, 2), 'count' => count($out)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Compute + create commission entries for the matched employees. */
    public function commit(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $cfg = $this->cfg($request);
            $tid = $request->user()->tenant_id;
            $month = trim((string) $request->input('month', ''));
            $status = $request->input('status') === 'approved' ? 'approved' : 'pending';
            $rows = $request->input('rows', []);
            if (! is_array($rows) || $month === '') {
                return response()->json(['ok' => false, 'error' => 'Month and at least one row are required.'], 422);
            }

            $created = 0;
            $skipped = [];
            FinYearController::stamp('commissions', $tid);     // ensure fin_year column
            $fy = FinYearController::fyOf($month);
            foreach ($rows as $r) {
                $r = (array) $r;
                [$payout] = $this->payoutFor($r, $cfg);
                if ($payout <= 0) {
                    continue;   // nothing to pay (e.g. target not met)
                }
                $emp = $this->resolveEmployee($tid, $r['emp_code'] ?? '', $r['employee'] ?? '');
                if (! $emp) {
                    $skipped[] = ($r['employee'] ?? $r['emp_code'] ?? '?');
                    continue;
                }
                $row = ApprovalService::safeRow('commissions', [
                    'tenant_id' => $tid,
                    'company_id' => $emp->company_id,
                    'employee_id' => $emp->id,
                    'portfolio' => $r['portfolio'] ?? null,
                    'amount' => $payout,
                    'cycle_month' => $month,
                    'month' => $month,
                    'fin_year' => $fy,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('commissions')->insert($row);
                $created++;
            }

            $msg = "Created {$created} commission entr".($created === 1 ? 'y' : 'ies')." for {$month} (status: {$status}).";
            if ($status === 'approved') {
                $msg .= ' They will fold into that month\'s payroll.';
            } else {
                $msg .= ' Approve them to reflect in payroll.';
            }
            if ($skipped) {
                $msg .= ' Skipped (no matching employee): '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? '…' : '').'.';
            }

            return response()->json(['ok' => true, 'created' => $created, 'skipped' => $skipped, 'message' => $msg]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- internals ----------------------------------------------------------

    private function cfg(Request $request): array
    {
        return [
            'basis' => in_array($request->input('basis'), ['collected', 'target', 'manual'], true) ? $request->input('basis') : 'collected',
            'formula' => in_array($request->input('formula'), ['flat', 'slab', 'portfolio'], true) ? $request->input('formula') : 'flat',
            'flat_rate' => (float) $request->input('flat_rate', 0),
            'threshold' => (float) $request->input('threshold', 100),
            'slabs' => is_array($request->input('slabs')) ? $request->input('slabs') : [],
            'portfolio_rates' => is_array($request->input('portfolio_rates')) ? $request->input('portfolio_rates') : [],
        ];
    }

    /** Returns [payout, achievementPctOrNull]. Mirrors the Python-verified model. */
    private function payoutFor(array $row, array $cfg): array
    {
        $collected = (float) ($row['collected'] ?? 0);
        $target = (float) ($row['target'] ?? 0);
        $manual = (float) ($row['amount'] ?? 0);
        $basis = $cfg['basis'];
        $formula = $cfg['formula'];

        if ($basis === 'manual') {
            return [round($manual, 2), null];
        }

        $fig = $collected;
        $ach = $target > 0 ? round($collected / $target * 100, 1) : 0.0;
        if ($basis === 'target' && ($target <= 0 || $ach < $cfg['threshold'])) {
            return [0.0, $ach];
        }

        $p = 0.0;
        if ($formula === 'flat') {
            $p = $fig * $cfg['flat_rate'] / 100;
        } elseif ($formula === 'slab') {
            $bands = $cfg['slabs'];
            $rate = count($bands) ? (float) ($bands[count($bands) - 1]['rate'] ?? 0) : 0.0;
            foreach ($bands as $b) {
                $upto = (float) ($b['upto'] ?? 0);
                if ($upto > 0 && $fig <= $upto) {
                    $rate = (float) ($b['rate'] ?? 0);
                    break;
                }
            }
            $p = $fig * $rate / 100;
        } else {
            $pf = strtolower(trim((string) ($row['portfolio'] ?? '')));
            $rate = 0.0;
            foreach ($cfg['portfolio_rates'] as $pr) {
                if (strtolower(trim((string) ($pr['name'] ?? ''))) === $pf && $pf !== '') {
                    $rate = (float) ($pr['rate'] ?? 0);
                    break;
                }
            }
            $p = $fig * $rate / 100;
        }

        return [round($p, 2), $basis === 'target' ? $ach : null];
    }

    private function resolveEmployee(?int $tid, string $code, string $name)
    {
        $q = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->whereNull('deleted_at');
        $code = trim($code);
        $name = trim($name);
        // "Name (CODE)" → pull the code out.
        if ($code === '' && preg_match('/\(([^)]+)\)\s*$/', $name, $m)) {
            $code = trim($m[1]);
        }
        if ($code !== '') {
            $e = (clone $q)->where('emp_code', $code)->first(['id', 'company_id']);
            if ($e) {
                return $e;
            }
        }
        if ($name !== '') {
            $bare = trim(preg_replace('/\([^)]*\)\s*$/', '', $name));
            $e = (clone $q)->whereRaw('LOWER(name) = ?', [strtolower($bare)])->first(['id', 'company_id']);
            if ($e) {
                return $e;
            }
        }

        return null;
    }
}
