<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * rev 107 — ON-PREM CLIENTS module in the SaaS admin panel (SRS FR-11).
 *
 * The perpetual-licence sales desk: client record → payments (manual entry;
 * gateway payments can be added later) → THE GATE (full payment = key
 * auto-eligible; partial = only with the "Activate on partial payment" tick,
 * Q2 default) → key generation with AMC expiry → key email to the client.
 * AMC renewals extend licences from here too.
 */
class OnpremClientController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    /** rev 107b: invoice + online-payment columns (Schema-guard convention). */
    public static function ensureSaleCols(): void
    {
        try {
            foreach ([
                'invoice_no' => fn ($t) => $t->string('invoice_no', 30)->nullable(),
                'invoice_token' => fn ($t) => $t->string('invoice_token', 64)->nullable(),
                'gateway_order_id' => fn ($t) => $t->string('gateway_order_id', 64)->nullable(),
            ] as $col => $def) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('onprem_clients', $col)) {
                    \Illuminate\Support\Facades\Schema::table('onprem_clients', $def);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** GST-inclusive total: licence price + 18% (CGST+SGST or IGST by state). */
    public static function totals(object $c): array
    {
        $price = (float) $c->price;
        $tax = round($price * 0.18, 2);
        $seller = (new BillingController)->publicSellerProfile();
        $intra = false;
        try {
            $intra = BillingController::buyerIsIntraState($c, $seller);
        } catch (\Throwable $e) {
        }

        return [
            'price' => $price, 'tax' => $tax, 'total' => round($price + $tax, 2),
            'intra' => $intra, 'seller' => $seller,
            'balance' => max(0, round($price + $tax - (float) $c->paid_total, 2)),
        ];
    }

    /** A client is fully paid when payments cover price + GST. */
    public static function fullyPaid(object $c): bool
    {
        return $c->price > 0 && (float) $c->paid_total >= self::totals($c)['total'];
    }

    public function index(Request $request)
    {
        $this->guard($request);
        LicenseService::ensureTables();
        self::ensureSaleCols();
        $clients = DB::table('onprem_clients')->orderByDesc('id')->limit(300)->get();
        $licences = DB::table('licences')->orderByDesc('id')->get()->groupBy('client_id');
        $payments = DB::table('onprem_payments')->orderByDesc('id')->get()->groupBy('client_id');

        return view('admin.onprem', [
            'clients' => $clients, 'licences' => $licences, 'payments' => $payments,
            'revealId' => (int) $request->query('reveal', 0),
        ]);
    }

    public function save(Request $request)
    {
        $this->guard($request);
        LicenseService::ensureTables();
        $v = $request->validate([
            'id' => ['nullable', 'integer'],
            'company' => ['required', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:2000'],
            'edition' => ['required', 'in:l1,l2,l3'],
            'employee_band' => ['nullable', 'string', 'max:30'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'amc_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $row = $v;
        unset($row['id']);
        $row['gstin'] = strtoupper(trim((string) ($row['gstin'] ?? ''))) ?: null;
        $row['updated_at'] = now();
        if (! empty($v['id'])) {
            DB::table('onprem_clients')->where('id', $v['id'])->update($row);
            $id = (int) $v['id'];
        } else {
            $row['created_at'] = now();
            $id = DB::table('onprem_clients')->insertGetId($row);
        }

        return redirect()->route('admin.onprem')->with('success', 'Client saved (#'.$id.').');
    }

    /** Record a payment (manual: NEFT/cheque/UPI/cash; gateway later). */
    public function payment(Request $request, int $id)
    {
        $this->guard($request);
        $v = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'mode' => ['required', 'in:neft,cheque,upi,gateway,cash'],
            'reference' => ['nullable', 'string', 'max:190'],
            'paid_on' => ['nullable', 'date'],
        ]);
        abort_unless(DB::table('onprem_clients')->where('id', $id)->exists(), 404);
        DB::table('onprem_payments')->insert([
            'client_id' => $id, 'amount' => $v['amount'], 'mode' => $v['mode'],
            'reference' => $v['reference'] ?? null, 'paid_on' => $v['paid_on'] ?? now()->toDateString(),
            'entered_by' => $request->user()->name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('onprem_clients')->where('id', $id)->update([
            'paid_total' => (float) DB::table('onprem_payments')->where('client_id', $id)->sum('amount'),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.onprem')->with('success', 'Payment recorded.');
    }

    /** The Q2 tick — allow key generation on partial payment (recorded). */
    public function partialToggle(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        DB::table('onprem_clients')->where('id', $id)->update([
            'activate_on_partial' => $c->activate_on_partial ? 0 : 1,
            'notes' => trim(($c->notes ?? '')."\n".now()->format('d M Y').': Activate-on-partial '.($c->activate_on_partial ? 'OFF' : 'ON').' by '.$request->user()->name),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.onprem')->with('success', 'Partial-payment activation '.($c->activate_on_partial ? 'disabled' : 'enabled').'.');
    }

    /** Generate the licence key (THE gate) + email it to the client. */
    public function issueKey(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        if (DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->exists()) {
            return redirect()->route('admin.onprem')->with('success', 'This client already has a live licence — revoke it first if you really need a new key.');
        }
        self::ensureSaleCols();
        $fullyPaid = self::fullyPaid($c);
        if (! $fullyPaid && ! $c->activate_on_partial) {
            return redirect()->route('admin.onprem')->with('success', 'Key NOT generated: payment is partial (₹'.number_format((float) $c->paid_total).' of ₹'.number_format(self::totals($c)['total']).' incl. GST). Tick "Activate on partial payment" if you approve.');
        }

        $amcExpiry = now()->addYear()->toDateString();   // first year of AMC from issue
        $key = LicenseService::issue($id, $c->edition, $amcExpiry);

        // Email the key + activation steps (fail-soft; key stays visible in panel).
        try {
            if ($c->email) {
                \App\Services\MailService::queue([
                    'tenant_id' => null,
                    'to' => $c->email,
                    'subject' => 'Your SmartPRS-'.strtoupper($c->edition).' licence key',
                    'heading' => 'Welcome to SmartPRS, '.($c->contact_name ?: $c->company).'!',
                    'intro' => 'Your perpetual licence is ready. Keep this key safe — it activates your installation.',
                    'lines' => [
                        'Licence key: '.$key,
                        'Edition: SmartPRS-'.strtoupper($c->edition),
                        'AMC (updates & support) valid till: '.$amcExpiry,
                        'Activation: open SmartPRS on your server, sign in as admin, and enter this key on the activation screen.',
                        'Help: ejaz@ametecsindia.com · WhatsApp 9000098877',
                    ],
                    'kind' => 'licence_key',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return redirect()->route('admin.onprem', ['reveal' => $id])->with('success', 'Licence key generated'.($c->email ? ' and emailed to '.$c->email : '').'. It is shown below — note it in the installation record.');
    }

    // ---------- rev 107b: invoice + online payment link (SRS FR-11 steps 2-3) ----------

    /** POST /admin/onprem/{id}/invoice — assign number + email PDF + pay link. */
    public function invoice(Request $request, int $id)
    {
        $this->guard($request);
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('id', $id)->first();
        abort_unless($c, 404);
        if ($c->price <= 0) {
            return redirect()->route('admin.onprem')->with('success', 'Set the licence price first — the invoice needs an amount.');
        }
        $upd = [];
        if (! $c->invoice_no) {
            $n = (int) DB::table('onprem_clients')->whereNotNull('invoice_no')->count() + 1;
            $upd['invoice_no'] = 'LIC-'.now()->format('Ym').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        }
        if (! $c->invoice_token) {
            $upd['invoice_token'] = Str::random(40);
        }
        if ($upd) {
            $upd['updated_at'] = now();
            DB::table('onprem_clients')->where('id', $id)->update($upd);
            $c = DB::table('onprem_clients')->where('id', $id)->first();
        }
        $t = self::totals($c);
        $payUrl = url('/licence/'.$c->invoice_token);
        try {
            if ($c->email) {
                \App\Services\MailService::queue([
                    'tenant_id' => null,
                    'to' => $c->email,
                    'subject' => 'SmartPRS licence invoice '.$c->invoice_no.' — '.$c->company,
                    'heading' => 'Your SmartPRS-'.strtoupper($c->edition).' licence invoice',
                    'intro' => 'Thank you for choosing SmartPRS. Your tax invoice is attached; you can pay securely online with the button below.',
                    'lines' => [
                        'Invoice: '.$c->invoice_no,
                        'Licence: SmartPRS-'.strtoupper($c->edition).' (perpetual)'.($c->employee_band ? ' · '.$c->employee_band : ''),
                        'Amount: ₹'.number_format($t['price'], 2).' + GST ₹'.number_format($t['tax'], 2).' = ₹'.number_format($t['total'], 2),
                        'Balance due: ₹'.number_format($t['balance'], 2),
                        'On full payment your licence key is generated and emailed automatically.',
                    ],
                    'cta_label' => 'Pay securely online',
                    'cta_url' => $payUrl,
                    'attach_b64' => base64_encode($this->buildInvoicePdf($c)->output()),
                    'attach_name' => $c->invoice_no.'.pdf',
                    'attach_mime' => 'application/pdf',
                    'kind' => 'licence_invoice',
                ]);
            }
        } catch (\Throwable $e) {
        }

        return redirect()->route('admin.onprem')->with('success', 'Invoice '.($upd['invoice_no'] ?? $c->invoice_no).' emailed'.($c->email ? ' to '.$c->email : '').'. Pay link: '.$payUrl);
    }

    private function buildInvoicePdf(object $c)
    {
        $t = self::totals($c);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('onprem-invoice-pdf', ['c' => $c, 't' => $t])
            ->setPaper('a4');
    }

    /** GET /licence/{token}/pdf — public invoice PDF (token-secured). */
    public function invoicePdf(string $token)
    {
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
        abort_unless($c && $c->invoice_no, 404);

        return $this->buildInvoicePdf($c)->stream($c->invoice_no.'.pdf');
    }

    /** GET /licence/{token} — public pay page (Razorpay, balance due). */
    public function payShow(string $token)
    {
        self::ensureSaleCols();
        $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
        abort_unless($c, 404);

        return view('licence-pay', ['c' => $c, 't' => self::totals($c), 'token' => $token]);
    }

    /** POST /licence/{token}/order — Razorpay order for the balance. */
    public function payOrder(Request $request, string $token)
    {
        try {
            self::ensureSaleCols();
            $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found.'], 404);
            }
            $t = self::totals($c);
            if ($t['balance'] <= 0) {
                return response()->json(['ok' => false, 'error' => 'This invoice is already fully paid — thank you!'], 422);
            }
            $creds = BillingController::razorpayCreds();
            if (! $creds) {
                return response()->json(['ok' => false, 'error' => 'Online payment is not configured yet. Please pay by NEFT and share the reference with Ametecs.'], 422);
            }
            $paise = (int) round($t['balance'] * 100);
            $resp = Http::withBasicAuth($creds['key'], $creds['secret'])->asForm()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $paise, 'currency' => 'INR', 'receipt' => 'LIC-'.$c->id,
                    'notes' => ['invoice' => $c->invoice_no, 'company' => $c->company],
                ]);
            if (! $resp->successful()) {
                return response()->json(['ok' => false, 'error' => 'Could not start the payment: '.$resp->body()], 422);
            }
            $orderId = $resp->json()['id'] ?? null;
            DB::table('onprem_clients')->where('id', $c->id)->update(['gateway_order_id' => $orderId, 'updated_at' => now()]);

            return response()->json(['ok' => true, 'orderId' => $orderId, 'keyId' => $creds['key'], 'amountPaise' => $paise]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /licence/{token}/complete — verify, record, auto-issue key when full. */
    public function payComplete(Request $request, string $token)
    {
        try {
            self::ensureSaleCols();
            $c = DB::table('onprem_clients')->where('invoice_token', $token)->first();
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found.'], 404);
            }
            $v = $request->validate([
                'razorpay_order_id' => ['required', 'string'],
                'razorpay_payment_id' => ['required', 'string'],
                'razorpay_signature' => ['required', 'string'],
            ]);
            if (! $c->gateway_order_id || $c->gateway_order_id !== $v['razorpay_order_id']) {
                return response()->json(['ok' => false, 'error' => 'Payment/order mismatch.'], 422);
            }
            $creds = BillingController::razorpayCreds();
            $expected = hash_hmac('sha256', $v['razorpay_order_id'].'|'.$v['razorpay_payment_id'], $creds['secret'] ?? '');
            if (! $creds || ! hash_equals($expected, $v['razorpay_signature'])) {
                return response()->json(['ok' => false, 'error' => 'Payment signature verification failed.'], 422);
            }
            // Idempotent on the payment id.
            if (! DB::table('onprem_payments')->where('client_id', $c->id)->where('reference', $v['razorpay_payment_id'])->exists()) {
                $t = self::totals($c);
                DB::table('onprem_payments')->insert([
                    'client_id' => $c->id, 'amount' => $t['balance'], 'mode' => 'gateway',
                    'reference' => $v['razorpay_payment_id'], 'paid_on' => now()->toDateString(),
                    'entered_by' => 'Razorpay', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('onprem_clients')->where('id', $c->id)->update([
                    'paid_total' => (float) DB::table('onprem_payments')->where('client_id', $c->id)->sum('amount'),
                    'updated_at' => now(),
                ]);
                $c = DB::table('onprem_clients')->where('id', $c->id)->first();
            }
            // Full payment → the key generates and emails itself (FR-11 step 4).
            $keyMsg = '';
            if (self::fullyPaid($c) && ! DB::table('licences')->where('client_id', $c->id)->whereIn('status', ['pending', 'active'])->exists()) {
                $key = LicenseService::issue($c->id, $c->edition, now()->addYear()->toDateString());
                $keyMsg = ' Your licence key has been emailed to '.($c->email ?: 'your registered address').'.';
                try {
                    if ($c->email) {
                        \App\Services\MailService::queue([
                            'tenant_id' => null,
                            'to' => $c->email,
                            'subject' => 'Payment received — your SmartPRS-'.strtoupper($c->edition).' licence key',
                            'heading' => 'Payment received with thanks, '.($c->contact_name ?: $c->company).'!',
                            'intro' => 'Your perpetual licence is ready. Keep this key safe — it activates your installation.',
                            'lines' => [
                                'Licence key: '.$key,
                                'Edition: SmartPRS-'.strtoupper($c->edition),
                                'AMC (updates & support) valid till: '.now()->addYear()->toDateString(),
                                'Activation: open SmartPRS on your server, sign in as admin, and enter this key.',
                                'Help: ejaz@ametecsindia.com · WhatsApp 9000098877',
                            ],
                            'kind' => 'licence_key',
                        ]);
                    }
                } catch (\Throwable $e) {
                }
            }

            return response()->json(['ok' => true, 'message' => 'Payment received — thank you!'.$keyMsg]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Renew AMC by one year (from current expiry or today, whichever is later). */
    public function renewAmc(Request $request, int $id)
    {
        $this->guard($request);
        $lic = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        abort_unless($lic, 404, 'No live licence for this client.');
        $base = max((string) $lic->amc_expires_on, now()->toDateString());
        $new = \Carbon\Carbon::parse($base)->addYear()->toDateString();
        DB::table('licences')->where('id', $lic->id)->update(['amc_expires_on' => $new, 'updated_at' => now()]);
        LicenseService::event($lic->id, 'amc_renewed', 'AMC extended to '.$new.' by '.$request->user()->name);

        return redirect()->route('admin.onprem')->with('success', 'AMC renewed till '.$new.'.');
    }

    /** Release the server binding so the client can re-activate after a move (Q5). */
    public function deactivate(Request $request, int $id)
    {
        $this->guard($request);
        $lic = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        abort_unless($lic, 404);
        DB::table('licences')->where('id', $lic->id)->update([
            'fingerprint' => null, 'server_name' => null,
            'reactivations_used' => (int) $lic->reactivations_used + 1,
            'updated_at' => now(),
        ]);
        LicenseService::event($lic->id, 'deactivated', 'Server binding released by '.$request->user()->name.' (move #'.((int) $lic->reactivations_used + 1).')');

        return redirect()->route('admin.onprem')->with('success', 'Server binding released — the client can activate on the new server now.');
    }

    /** Revoke (fraud/non-payment). Per Q4: blocks activation + updates, never locks the running app. */
    public function revoke(Request $request, int $id)
    {
        $this->guard($request);
        $lic = DB::table('licences')->where('client_id', $id)->whereIn('status', ['pending', 'active'])->orderByDesc('id')->first();
        abort_unless($lic, 404);
        DB::table('licences')->where('id', $lic->id)->update(['status' => 'revoked', 'updated_at' => now()]);
        LicenseService::event($lic->id, 'revoked', 'Revoked by '.$request->user()->name);

        return redirect()->route('admin.onprem')->with('success', 'Licence revoked — activation and updates are blocked for it.');
    }
}
