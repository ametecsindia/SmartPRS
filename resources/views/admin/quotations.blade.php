@extends('admin.layout')
@section('title', 'Quotations')
@section('nav_quotes', 'active')

@section('content')
    <h1>Quotations</h1>
    <p class="sub">Quotes sent from the signup page that are awaiting payment. Each carries a public pay link; the workspace is created automatically when paid.</p>

    <table>
        <thead>
            <tr><th>Quote #</th><th>Company / Contact</th><th>Plan</th><th>Employees</th><th>Total</th><th>Sent</th><th>Valid until</th><th>Link</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php
                    $valid = $r->quoted_at ? \Carbon\Carbon::parse($r->quoted_at)->addDays($validDays) : null;
                    $expired = $valid && $valid->isPast();
                @endphp
                <tr>
                    <td><b>{{ $r->quote_no }}</b></td>
                    <td>{{ $r->company }}<br><span style="color:var(--text2);font-size:13px;">{{ $r->admin_name }} · {{ $r->admin_email }}</span></td>
                    <td>{{ $planNames[$r->plan_id] ?? '—' }}</td>
                    <td>{{ $r->seats }}{{ ($r->companies ?? 1) > 1 ? ' · '.$r->companies.' cos' : '' }}</td>
                    <td>₹{{ number_format($r->amount + $r->tax, 2) }}</td>
                    <td style="white-space:nowrap;">{{ $r->quoted_at ? \Carbon\Carbon::parse($r->quoted_at)->format('d M Y') : '—' }}</td>
                    <td style="white-space:nowrap;">
                        @if($expired)<span class="pill" style="background:rgba(239,68,68,.12);color:#dc2626;">Expired {{ $valid->format('d M') }}</span>
                        @else {{ $valid ? $valid->format('d M Y') : '—' }}@endif
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="{{ url('/quote/'.$r->quote_token) }}" target="_blank" style="color:var(--accent);">Pay page</a> ·
                        <a href="{{ url('/quote/'.$r->quote_token.'/pdf') }}" target="_blank" style="color:var(--text2);">PDF</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:28px;">No open quotations. They appear here when a visitor clicks “Send a Quotation” on the signup page.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
