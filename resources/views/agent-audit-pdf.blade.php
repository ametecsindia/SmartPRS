@php
    $color = $brand['color'] ?? '#ea580c';
    $brandName = $brand['display_name'] ?? ($company->name ?? 'Company');
    $brandLogo = $brand['logo_file'] ?? ($brand['logo'] ?? '');
    $addr = $company->address ?? '';
    $gstin = $company->gstin ?? '';
    $vColors = ['ok' => ['#15803d', '#f0fdf4'], 'warn' => ['#b45309', '#fffbeb'], 'bad' => ['#b91c1c', '#fef2f2']];
    $vLabel = ['ok' => 'Compliant', 'warn' => 'Attention', 'bad' => 'Non-compliant'];
    $verdictColor = $fail > 0 ? '#b91c1c' : ($warn > 0 ? '#b45309' : '#15803d');
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { margin: 0; color: #1f2937; font-size: 11px; }
    .head { border-bottom: 3px solid {{ $color }}; padding: 0 0 10px; margin-bottom: 4px; }
    .head td { vertical-align: top; }
    .co { font-size: 19px; font-weight: bold; color: #111827; }
    .co-sub { font-size: 9.5px; color: #6b7280; line-height: 1.5; }
    .rpt { text-align: right; font-size: 9.5px; color: #6b7280; line-height: 1.6; }
    .rpt b { color: #111827; }
    .band { background: {{ $color }}; color: #fff; padding: 8px 12px; font-size: 14px; font-weight: bold; margin: 8px 0 0; }
    .band .v { float: right; background: #fff; color: {{ $verdictColor }}; font-size: 10px; padding: 2px 10px; border-radius: 10px; }
    .meta { width: 100%; border-collapse: collapse; margin: 10px 0 6px; }
    .meta td { border: 1px solid #e5e7eb; padding: 5px 8px; width: 25%; }
    .meta .k { font-size: 8px; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; }
    .meta .val { font-size: 11px; font-weight: bold; }
    .score { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
    .score td { text-align: center; padding: 7px; border: 1px solid #e5e7eb; background: #f9fafb; width: 25%; }
    .score .big { font-size: 20px; font-weight: bold; }
    .score .lbl { font-size: 8px; text-transform: uppercase; color: #9ca3af; letter-spacing: .4px; }
    h2 { font-size: 12px; color: #111827; border-left: 4px solid {{ $color }}; padding-left: 8px; margin: 14px 0 4px; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; font-size: 8px; text-transform: uppercase; color: #9ca3af; letter-spacing: .3px; }
    table.p td { border: 1px solid #e5e7eb; padding: 5px 8px; font-size: 10.5px; }
    .pill { font-size: 9px; font-weight: bold; padding: 1px 8px; border-radius: 9px; }
    .foot { margin-top: 18px; border-top: 2px solid {{ $color }}; padding-top: 8px; font-size: 8.5px; color: #6b7280; line-height: 1.6; }
    .sign td { padding-top: 28px; font-size: 9px; color: #6b7280; text-align: center; }
    .sign .l { border-top: 1px solid #111827; }
</style>
</head>
<body>

    <table class="head" width="100%"><tr>
        <td width="62%">
            @if(!empty($brandLogo))
                <img src="{{ $brandLogo }}" style="max-height:38px;max-width:170px;object-fit:contain;margin-bottom:4px;"><br>
            @endif
            <span class="co">{{ $brandName }}</span>
            <div class="co-sub">
                @if($addr){{ $addr }}<br>@endif
                @if($gstin)GSTIN: {{ $gstin }}@endif
            </div>
        </td>
        <td width="38%" class="rpt">
            <b>Recovery Agent Compliance File</b><br>
            Report Ref: <b>{{ $ref }}</b><br>
            Generated: <b>{{ $generatedAt }}</b><br>
            Audit period: {{ $auditFrom }} – {{ $auditTo }}<br>
            Tamper-evident hash: <b>{{ $hash }}</b>
        </td>
    </tr></table>

    <div class="band">Recovery Agent — RBI Compliance Audit Report
        <span class="v">{{ $verdict }}</span>
    </div>

    <table class="meta"><tr>
        <td><div class="k">Agent Name</div><div class="val">{{ $e->name }}</div></td>
        <td><div class="k">Agent Code</div><div class="val">{{ $e->emp_code }}</div></td>
        <td><div class="k">Designation</div><div class="val">{{ $e->designation ?? '—' }}</div></td>
        <td><div class="k">Status</div><div class="val">{{ ucfirst($e->status ?? 'active') }}</div></td>
    </tr><tr>
        <td><div class="k">Date of Joining</div><div class="val">{{ $e->doj ?? '—' }}</div></td>
        <td><div class="k">Mobile</div><div class="val">{{ $e->mobile ?? '—' }}</div></td>
        <td><div class="k">Department</div><div class="val">{{ $e->department ?? '—' }}</div></td>
        <td><div class="k">Compliance Score</div><div class="val" style="color:{{ $verdictColor }}">{{ $score }}%</div></td>
    </tr></table>

    <table class="score"><tr>
        <td><div class="big" style="color:#15803d">{{ $pass }}</div><div class="lbl">Compliant</div></td>
        <td><div class="big" style="color:#b45309">{{ $warn }}</div><div class="lbl">Needs attention</div></td>
        <td><div class="big" style="color:#b91c1c">{{ $fail }}</div><div class="lbl">Non-compliant</div></td>
        <td><div class="big" style="color:{{ $color }}">{{ $score }}%</div><div class="lbl">Overall score</div></td>
    </tr></table>

    @foreach($groups as $gname => $rows)
        <h2>{{ $gname }}</h2>
        <table class="p">
            <tr><th width="34%">Parameter</th><th width="24%">On Record</th><th width="15%">Status</th><th>Evidence</th></tr>
            @foreach($rows as $r)
                @php $vc = $vColors[$r['state']]; @endphp
                <tr>
                    <td>{{ $r['param'] }}</td>
                    <td>{{ $r['value'] }}</td>
                    <td><span class="pill" style="color:{{ $vc[0] }};background:{{ $vc[1] }}">{{ $vLabel[$r['state']] }}</span></td>
                    <td style="color:#6b7280">{{ $r['evidence'] ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <table class="sign" width="100%"><tr>
        <td width="33%"><div class="l">Prepared by (Compliance Officer)</div></td>
        <td width="34%"><div class="l">Reviewed by (Operations Head)</div></td>
        <td width="33%"><div class="l">For {{ $brandName }}</div></td>
    </tr></table>

    <div class="foot">
        Generated from live records by the SmartPRS Recovery-Agent Compliance module. This report is a tamper-evident record (hash-chained audit log).
        Regulatory basis: RBI Guidelines on Recovery Agents, RBI Outsourcing Directions 2025, and IIBF DRA certification norms — indicative; verify against the latest RBI directions applicable to the engaging bank/NBFC before filing.
    </div>

</body>
</html>
