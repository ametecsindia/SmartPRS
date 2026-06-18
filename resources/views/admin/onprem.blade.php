@extends('admin.layout')
@section('title', 'On-Prem Clients')
@section('nav_onprem', 'active')

@section('content')
    <h1>On-Prem Clients &amp; Licences</h1>
    <p class="sub">The perpetual-licence sales desk: record the client → record payments → generate the key (full payment, or partial with your tick) → the key is emailed and shown here for the installing engineer. AMC renewals and server moves are managed here too.</p>

    @if (session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif

    <details style="margin-bottom:22px;" {{ request('edit') ? 'open' : '' }}>
        <summary style="cursor:pointer;font-weight:700;color:var(--accent);margin-bottom:10px;">+ New client</summary>
        <form method="POST" action="{{ route('admin.onprem.save') }}" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
            @csrf
            <label>Company *<input name="company" required></label>
            <label>Contact person<input name="contact_name"></label>
            <label>Email (key &amp; updates go here)<input type="email" name="email"></label>
            <label>Mobile<input name="mobile"></label>
            <label>GSTIN<input name="gstin"></label>
            <label>State<input name="state"></label>
            <label>Edition *
                <select name="edition"><option value="l1">SmartPRS-L1 (Core)</option><option value="l2">SmartPRS-L2 (Advanced)</option><option value="l3">SmartPRS-L3 (Collections DNA)</option></select>
            </label>
            <label>Employee band<input name="employee_band" placeholder="up to 250"></label>
            <label>Licence price (₹)<input type="number" step="0.01" name="price"></label>
            <label>AMC %<input type="number" step="0.01" name="amc_percent" value="18"></label>
            {{-- rev140 — Super Admin sets HOW LONG the client may use the app.
                 A duration, OR an exact expiry date (the date wins if both set).
                 This becomes the licence's expiry, enforced at the client login. --}}
            <label>Access validity
                <select name="licence_term_months">
                    <option value="12">1 year</option>
                    <option value="24">2 years</option>
                    <option value="36">3 years</option>
                    <option value="60">5 years</option>
                    <option value="6">6 months</option>
                    <option value="3">3 months</option>
                    <option value="1">1 month</option>
                    <option value="">Use exact date →</option>
                </select>
            </label>
            <label>Or exact expiry date<input type="date" name="licence_expires_on"></label>
            <label style="grid-column:span 1;">&nbsp;<span style="display:block;font-weight:400;color:#94a3b8;font-size:11px;margin-top:8px;line-height:1.4;">Applied when the key is generated. Leave the date blank to use the duration.</span></label>
            <label style="grid-column:span 2;">Address<input name="address"></label>
            <label style="grid-column:span 3;">Notes<input name="notes"></label>
            <div><button class="btn btn-primary" type="submit">Save client</button></div>
        </form>
    </details>

    @forelse ($clients as $c)
        @php
            $lics = $licences->get($c->id, collect());
            $live = $lics->whereIn('status', ['pending', 'active'])->first();
            $pays = $payments->get($c->id, collect());
            $tot = \App\Http\Controllers\OnpremClientController::totals($c);
            $fullyPaid = \App\Http\Controllers\OnpremClientController::fullyPaid($c);
        @endphp
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;">
                <div>
                    <strong style="font-size:15px;">{{ $c->company }}</strong>
                    <span style="background:#0c1929;color:#fff;border-radius:6px;font-size:11px;font-weight:700;padding:2px 8px;margin-left:8px;">SmartPRS-{{ strtoupper($c->edition) }}</span>
                    @if ($live)
                        <span style="background:{{ $live->status === 'active' ? '#16a34a' : '#f59e0b' }};color:#fff;border-radius:6px;font-size:11px;font-weight:700;padding:2px 8px;margin-left:4px;">{{ strtoupper($live->status) }}{{ $live->key_last4 ? ' ·…'.$live->key_last4 : '' }}</span>
                        <span style="font-size:11px;color:#64748b;margin-left:6px;">Valid till {{ $live->amc_expires_on ?: '—' }}</span>
                    @endif
                </div>
                <div style="font-size:12px;color:#64748b;">{{ $c->contact_name }} · {{ $c->email }} · {{ $c->mobile }}</div>
            </div>
            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;font-size:13px;align-items:center;">
                <span>Price: <strong>₹{{ number_format((float) $c->price) }}</strong> <span style="color:#64748b;">(+GST = ₹{{ number_format($tot['total']) }})</span></span>
                <span>Paid: <strong style="color:{{ $fullyPaid ? '#16a34a' : '#f59e0b' }};">₹{{ number_format((float) $c->paid_total) }}</strong> ({{ $pays->count() }} payment{{ $pays->count() === 1 ? '' : 's' }})</span>
                <span>Balance: <strong>₹{{ number_format($tot['balance']) }}</strong></span>
                <span>AMC: {{ $c->amc_percent }}%</span>
                @if ($c->invoice_no ?? false)
                    <span style="color:#64748b;">{{ $c->invoice_no }} · <a href="{{ url('/licence/'.$c->invoice_token) }}" target="_blank" style="color:var(--accent);">pay link</a></span>
                @endif
                @if (! $fullyPaid)
                    <form method="POST" action="{{ route('admin.onprem.partial', $c->id) }}" style="display:inline;">@csrf
                        <button class="btn btn-outline" style="font-size:11px;">{{ $c->activate_on_partial ? '✓ Partial-activation ON — click to remove' : 'Allow activation on partial payment' }}</button>
                    </form>
                @endif
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:flex-end;">
                <form method="POST" action="{{ route('admin.onprem.payment', $c->id) }}" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">@csrf
                    <label style="font-size:11px;">Amount<br><input type="number" step="0.01" name="amount" style="width:110px;" required></label>
                    <label style="font-size:11px;">Mode<br><select name="mode" style="width:100px;"><option>neft</option><option>cheque</option><option>upi</option><option>gateway</option><option>cash</option></select></label>
                    <label style="font-size:11px;">Reference<br><input name="reference" style="width:140px;"></label>
                    <button class="btn btn-outline" type="submit">Record payment</button>
                </form>
                <form method="POST" action="{{ route('admin.onprem.invoice', $c->id) }}" style="display:inline;">@csrf
                    <button class="btn btn-outline" type="submit">{{ ($c->invoice_no ?? false) ? 'Re-send invoice + pay link' : 'Email invoice + pay link' }}</button>
                </form>
                @if (! $live)
                    <form method="POST" action="{{ route('admin.onprem.key', $c->id) }}" style="display:inline;">@csrf
                        <button class="btn btn-primary" type="submit" {{ ($fullyPaid || $c->activate_on_partial) ? '' : 'disabled title=Payment-pending' }}>Generate licence key</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.onprem.renew', $c->id) }}" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">@csrf
                        <label style="font-size:11px;">Extend until (optional)<br><input type="date" name="renew_until" style="width:150px;"></label>
                        <button class="btn btn-outline" type="submit">Renew licence</button>
                    </form>
                    <form method="POST" action="{{ route('admin.onprem.deactivate', $c->id) }}" style="display:inline;" onsubmit="return confirm('Release the server binding so the client can activate on a NEW server?');">@csrf<button class="btn btn-outline">Release server binding</button></form>
                    <form method="POST" action="{{ route('admin.onprem.revoke', $c->id) }}" style="display:inline;" onsubmit="return confirm('REVOKE this licence? Activation and updates will be blocked for it.');">@csrf<button class="btn btn-outline" style="color:#dc2626;border-color:#fca5a5;">Revoke</button></form>
                @endif
            </div>
            @if ($live && $revealId === (int) $c->id)
                <div style="margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 14px;font-family:Consolas,monospace;font-size:16px;letter-spacing:1px;">
                    {{ \App\Services\LicenseService::reveal($live) ?? 'Could not decrypt the key on this server.' }}
                    <div style="font-size:11px;color:#9a3412;font-family:inherit;margin-top:4px;">Shown because you just generated it — note it in the installation record. It was also emailed{{ $c->email ? ' to '.$c->email : '' }}.</div>
                </div>
            @endif
        </div>
    @empty
        <p style="color:#64748b;">No on-prem clients yet — add the first one above.</p>
    @endforelse

    <style>
        label { font-size: 12px; color: #475569; font-weight: 600; display: block; }
        label input, label select { width: 100%; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; margin-top: 4px; }
    </style>
@endsection
