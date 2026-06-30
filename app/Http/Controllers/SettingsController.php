<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory rate configuration. Stores per-tenant overrides of the Indian
 * payroll/statutory rates (PF cap & rate, ESI threshold & rates, PT, TDS new-
 * regime slabs, standard deduction, 87A rebate, cess, Sec 194H commission rate,
 * and the no-PAN higher-TDS rate) in a self-creating `statutory_settings` table.
 *
 * AppDataController reads the effective rates via SettingsController::rates()
 * so the payroll/PF/ESIC/TDS math and the statutory PDFs use the configured
 * values instead of hardcoded constants. The prototype's Statutory screen reads
 * the same rates (returned by /app/data) so the on-screen tables match.
 *
 * Table is created on first use (no migration required), matching the project's
 * self-creating-table convention (see KbController / AttendanceReportController).
 */
class SettingsController extends Controller
{
    /** Default Indian statutory rates (FY 2025-26, new regime). */
    public static function defaults(): array
    {
        return [
            'pf_wage_cap' => 15000,       // PF wage ceiling (₹)
            'pf_rate' => 12,              // PF % each side (employee & employer)
            'esi_threshold' => 21000,     // gross ≤ this is ESI-eligible (₹)
            'esi_employee_rate' => 0.75,  // ESI employee %
            'esi_employer_rate' => 3.25,  // ESI employer %
            'pt_amount' => 200,           // Professional Tax / month (₹)
            'std_deduction' => 50000,     // salary standard deduction (₹)
            'rebate_87a_limit' => 700000, // 87A rebate: nil tax up to this (₹)
            'cess_rate' => 4,             // health & education cess %
            'comm_tds_rate' => 5,         // Sec 194H commission TDS %
            'no_pan_tds_rate' => 20,      // higher TDS % when deductee has no PAN
            'incentive_min_compliance' => 60, // F1 — min compliance score (0–100) to pay an incentive without an override note
            'data_retention_months' => 84,    // G5 — record / recording retention period (months); 84 = 7 years
            'contact_window_start' => '08:00', // H1 — lawful borrower-contact window start (RBI 08:00–19:00)
            'contact_window_end' => '19:00',   // H1 — lawful borrower-contact window end
            'tds_slabs' => [              // new-regime annual slabs; upto 0 = "and above"
                ['upto' => 300000, 'rate' => 0],
                ['upto' => 700000, 'rate' => 5],
                ['upto' => 1000000, 'rate' => 10],
                ['upto' => 1200000, 'rate' => 15],
                ['upto' => 1500000, 'rate' => 20],
                ['upto' => 0, 'rate' => 30],
            ],
        ];
    }

    /** Create the statutory_settings table on the fly if migrations were not run. */
    private static function ensureTable(): void
    {
        if (Schema::hasTable('statutory_settings')) {
            return;
        }
        Schema::create('statutory_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
    }

    /** Effective rates for a tenant = defaults overlaid with saved overrides. */
    public static function rates(?int $tenantId): array
    {
        self::ensureTable();
        $row = DB::table('statutory_settings')->where('tenant_id', $tenantId ?? 0)->value('value');
        $saved = $row ? (json_decode($row, true) ?: []) : [];
        $rates = array_merge(self::defaults(), $saved);
        if (empty($rates['tds_slabs']) || ! is_array($rates['tds_slabs'])) {
            $rates['tds_slabs'] = self::defaults()['tds_slabs'];
        }

        return $rates;
    }

    public function index(Request $request)
    {
        return response()->json([
            'rates' => self::rates($request->user()->tenant_id),
            'defaults' => self::defaults(),
            'canManage' => $request->user()->hasAnyRole(['super_admin', 'admin']),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'admin']), 403);
        self::ensureTable();

        $num = ['nullable', 'numeric', 'min:0'];
        $v = $request->validate([
            'pf_wage_cap' => $num, 'pf_rate' => $num,
            'esi_threshold' => $num, 'esi_employee_rate' => $num, 'esi_employer_rate' => $num,
            'pt_amount' => $num, 'std_deduction' => $num, 'rebate_87a_limit' => $num,
            'cess_rate' => $num, 'comm_tds_rate' => $num, 'no_pan_tds_rate' => $num,
            'tds_slabs' => ['nullable', 'array'],
            'tds_slabs.*.upto' => ['nullable', 'numeric', 'min:0'],
            'tds_slabs.*.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Start from defaults so any field left out stays valid.
        $merged = self::defaults();
        foreach (self::defaults() as $k => $default) {
            if ($k === 'tds_slabs') {
                continue;
            }
            if (array_key_exists($k, $v) && $v[$k] !== null && $v[$k] !== '') {
                $merged[$k] = $v[$k] + 0; // numeric
            }
        }
        if (! empty($v['tds_slabs'])) {
            $merged['tds_slabs'] = array_values(array_map(fn ($s) => [
                'upto' => (float) ($s['upto'] ?? 0),
                'rate' => (float) ($s['rate'] ?? 0),
            ], $v['tds_slabs']));
        }

        $tenantId = $request->user()->tenant_id ?? 0;
        DB::table('statutory_settings')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['value' => json_encode($merged), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['ok' => true, 'rates' => $merged]);
    }
}
