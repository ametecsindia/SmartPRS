<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computed statutory reports (rev 40): Gratuity provision + Professional Tax.
 * These have no table of their own — they are derived live from active
 * employees + the configured statutory rates (reusing AppDataController::
 * computeSlip so basic/gross match payroll). Read-only, tenant-scoped,
 * admin/HR guarded, fail-soft JSON. Returns {columns, rows, note} the SPA
 * renders as a simple table.
 */
class StatutoryController extends Controller
{
    public function report(Request $request, string $type)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $rates = SettingsController::rates($tid);
            $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

            $emps = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('emp_code')
                ->get(['id', 'emp_code', 'name', 'ctc', 'doj', 'pt_state']);

            if ($type === 'gratuity') {
                $cols = ['Code', 'Name', 'DOJ', 'Years', 'Monthly Basic', 'Eligible', 'Gratuity'];
                $rows = [];
                $total = 0.0;
                foreach ($emps as $e) {
                    $s = AppDataController::computeSlip((float) $e->ctc, $rates);
                    $years = $e->doj ? Carbon::parse($e->doj)->diffInDays(now()) / 365.25 : 0;
                    $elig = $years >= 5;
                    $grat = $elig ? round((15 / 26) * $s['basic'] * floor($years)) : 0;
                    $total += $grat;
                    $rows[] = ['Code' => $e->emp_code, 'Name' => $e->name, 'DOJ' => $e->doj ?: '—',
                        'Years' => round($years, 1), 'Monthly Basic' => $s['basic'],
                        'Eligible' => $elig ? 'Yes' : 'No (<5y)', 'Gratuity' => $grat];
                }

                return response()->json([
                    'ok' => true,
                    'label' => 'Gratuity provision (payable on exit, 5+ years of service)',
                    'columns' => $cols,
                    'rows' => $rows,
                    'note' => 'Formula: (15 ÷ 26) × last monthly Basic × completed years; eligible at 5+ years. Total provisioned: ₹'.number_format($total),
                ]);
            }

            if ($type === 'pt') {
                $pt = (float) $rates['pt_amount'];
                $cols = ['Code', 'Name', 'State', 'Monthly Gross', 'Monthly PT', 'Annual PT'];
                $rows = [];
                $total = 0.0;
                foreach ($emps as $e) {
                    $s = AppDataController::computeSlip((float) $e->ctc, $rates);
                    $mpt = $s['gross'] > 0 ? $pt : 0;
                    $total += $mpt * 12;
                    $rows[] = ['Code' => $e->emp_code, 'Name' => $e->name, 'State' => $e->pt_state ?: '—',
                        'Monthly Gross' => $s['gross'], 'Monthly PT' => $mpt, 'Annual PT' => $mpt * 12];
                }

                return response()->json([
                    'ok' => true,
                    'label' => 'Professional Tax (per the configured monthly slab)',
                    'columns' => $cols,
                    'rows' => $rows,
                    'note' => 'Monthly PT = ₹'.number_format($pt).' per employee (edit in Settings → Statutory Rates). Total annual PT across active staff: ₹'.number_format($total),
                ]);
            }

            return response()->json(['ok' => false, 'error' => 'Unknown statutory report'], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}
