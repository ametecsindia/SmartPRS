<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #0f172a; font-size: 12px; }
        .head { background: #0c1929; color: #fff; padding: 18px 24px; }
        .head table { width: 100%; }
        .logo { width: 34px; height: 34px; background: #f97316; border-radius: 8px; color: #fff;
                text-align: center; font-size: 18px; font-weight: bold; }
        .brand { font-size: 18px; font-weight: bold; }
        .brand span { color: #f97316; }
        .muted { color: #94a3b8; font-size: 11px; }
        .wrap { padding: 22px 24px; }
        .meta td { padding: 3px 0; font-size: 12px; }
        .label { color: #64748b; text-transform: uppercase; font-size: 9px; letter-spacing: .5px; }
        h2 { font-size: 14px; margin: 18px 0 6px; color: #0c1929; }
        table.grid { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.grid th { background: #f8fafc; text-align: left; padding: 8px 10px; font-size: 10px;
                        text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        table.grid td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
        .amt { text-align: right; }
        .tot td { font-weight: bold; border-top: 2px solid #0c1929; }
        .net { margin-top: 16px; background: #0c1929; color: #fff; padding: 14px 18px; border-radius: 8px; }
        .net .big { font-size: 20px; font-weight: bold; }
        .foot { margin-top: 16px; color: #94a3b8; font-size: 10px; }
    </style>
</head>
<body>
    <div class="head">
        <table>
            <tr>
                <td style="width:46px;">
                    @if (!empty($brand['logo']))
                        <img src="{{ $brand['logo'] }}" style="max-height:38px;max-width:120px;object-fit:contain;">
                    @else
                        <div class="logo" style="background: {{ $brand['color'] ?? '#f97316' }};">{{ strtoupper(substr($brand['display_name'] ?? 'S', 0, 1)) }}</div>
                    @endif
                </td>
                <td>
                    <div class="brand">{{ $brand['display_name'] ?? ($company->name ?? 'SmartPRS') }}</div>
                    <div class="muted">{{ $brand['tagline'] ?? '' ?: ($company->name ?? 'SmartPRS') }}</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-size:14px;font-weight:bold;">PAYSLIP</div>
                    <div class="muted">{{ $monthLabel }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="wrap">
        <table class="meta">
            <tr>
                <td style="width:50%;"><div class="label">Employee</div>{{ $e->name }}</td>
                <td><div class="label">Employee Code</div>{{ $e->emp_code }}</td>
            </tr>
            <tr>
                <td><div class="label">Type</div>{{ ucfirst($e->type) }}</td>
                <td><div class="label">PAN</div>{{ $e->pan ?? '—' }}</td>
            </tr>
            <tr>
                <td><div class="label">PF Number (UAN)</div>{{ $e->uan ?? '—' }}</td>
                <td><div class="label">ESI</div>{{ ($e->esi_applicable ?? '') === 'yes' ? 'Applicable' : '—' }}</td>
            </tr>
            <tr>
                <td><div class="label">Bank A/C</div>{{ $e->bank_acc ?? '—' }} ({{ $e->ifsc ?? '—' }})</td>
                <td><div class="label">Annual CTC</div>₹{{ number_format($e->ctc, 2) }}</td>
            </tr>
        </table>

        <table style="width:100%;">
            <tr>
                <td style="width:50%;vertical-align:top;padding-right:8px;">
                    <h2>Earnings</h2>
                    <table class="grid">
                        <tr><th>Component</th><th class="amt">Amount</th></tr>
                        @if (!empty($earnMap))
                            @foreach ($earnMap as $name => $amt)
                                <tr><td>{{ $name }}</td><td class="amt">₹{{ number_format((float) $amt, 2) }}</td></tr>
                            @endforeach
                        @else
                            <tr><td>Basic</td><td class="amt">₹{{ number_format($s['basic'], 2) }}</td></tr>
                            <tr><td>HRA</td><td class="amt">₹{{ number_format($s['hra'], 2) }}</td></tr>
                            <tr><td>Special Allowance</td><td class="amt">₹{{ number_format($s['special'], 2) }}</td></tr>
                            @if (!empty($s['commission']))
                                <tr><td>Commission</td><td class="amt">₹{{ number_format($s['commission'], 2) }}</td></tr>
                            @endif
                        @endif
                        <tr class="tot"><td>Gross</td><td class="amt">₹{{ number_format($s['gross'], 2) }}</td></tr>
                    </table>
                </td>
                <td style="width:50%;vertical-align:top;padding-left:8px;">
                    <h2>Deductions</h2>
                    <table class="grid">
                        <tr><th>Component</th><th class="amt">Amount</th></tr>
                        @if (!empty($dedMap))
                            @foreach ($dedMap as $name => $amt)
                                <tr><td>{{ $name }}</td><td class="amt">₹{{ number_format((float) $amt, 2) }}</td></tr>
                            @endforeach
                        @else
                            <tr><td>Provident Fund (PF)</td><td class="amt">₹{{ number_format($s['pf'], 2) }}</td></tr>
                            <tr><td>ESI</td><td class="amt">₹{{ number_format($s['esi'], 2) }}</td></tr>
                            <tr><td>Professional Tax</td><td class="amt">₹{{ number_format($s['pt'], 2) }}</td></tr>
                            <tr><td>TDS</td><td class="amt">₹{{ number_format($s['tds'], 2) }}</td></tr>
                        @endif
                        <tr class="tot"><td>Total Deductions</td><td class="amt">₹{{ number_format($s['total_ded'], 2) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="net" style="width:100%;">
            <tr>
                <td>Net Pay ({{ $monthLabel }})</td>
                <td style="text-align:right;" class="big">₹{{ number_format($s['net'], 2) }}</td>
            </tr>
        </table>

        @if (!empty($note))
            <h2>How this was calculated</h2>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;font-size:11px;line-height:1.6;color:#334155;">
                {{ $note }}
            </div>
        @endif

        <div class="foot">
            Computer-generated payslip · net = gross − statutory deductions (PF/ESI/PT). Figures indicative; configure exact statutory rules in Settings.
            Generated {{ now()->format('d M Y, H:i') }}.
        </div>
    </div>
</body>
</html>
