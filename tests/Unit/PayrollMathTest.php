<?php

use App\Http\Controllers\AppDataController;

/*
 * Pure payroll math — no DB, no auth. Verifies the CTC → monthly breakdown that
 * the whole payroll flow (Generate Payroll, payslips, vouchers) relies on, using
 * the default statutory rates (PF 12% capped at 15k basic, ESI 0.75% ≤ 21k gross,
 * PT ₹200).
 */

test('computeSlip splits a ₹6,00,000 CTC into the right monthly components', function () {
    $s = AppDataController::computeSlip(600000);

    expect($s['gross'])->toBe(50000.0)
        ->and($s['basic'])->toBe(25000.0)
        ->and($s['hra'])->toBe(10000.0)
        ->and($s['special'])->toBe(15000.0)
        ->and($s['pf'])->toBe(1800.0)      // min(25000, 15000 cap) × 12%
        ->and($s['esi'])->toBe(0.0)        // gross > 21,000 → not ESI-eligible
        ->and($s['pt'])->toBe(200.0)
        ->and($s['total_ded'])->toBe(2000.0)
        ->and($s['net'])->toBe(48000.0);
});

test('ESI applies when gross is at/under the threshold', function () {
    $s = AppDataController::computeSlip(240000);   // gross 20,000 ≤ 21,000

    expect($s['gross'])->toBe(20000.0)
        ->and($s['pf'])->toBe(1200.0)      // min(10000,15000) × 12%
        ->and($s['esi'])->toBe(150.0)      // 20,000 × 0.75%
        ->and($s['net'])->toBe(18450.0);
});

test('a zero CTC yields a zero slip', function () {
    $s = AppDataController::computeSlip(0);

    expect($s['gross'])->toBe(0.0)
        ->and($s['net'])->toBe(0.0)
        ->and($s['pt'])->toBe(0.0);        // PT only when gross > 0
});
