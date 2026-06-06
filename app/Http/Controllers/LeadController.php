<?php

namespace App\Http\Controllers;

use App\Services\MailService;
use App\Services\WaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * rev 89 — Lead generation (Ejaz, 6 Jun 2026, modelled on smartdcm.app).
 *
 * Public demo-request form on the landing page → `leads` table (self-created),
 * plus FAIL-SOFT alerts: email to contact.lead_email and a WhatsApp template
 * via Interakt (env INTERAKT_TEMPLATE_LEAD) to contact.lead_wa. The numbers
 * and recipients are edited in the Landing CMS (/admin/landing), NOT in code.
 * Super-admin views/works the leads at /admin/leads.
 */
class LeadController extends Controller
{
    /** Self-create the leads table (project convention — no migration). */
    private static function ensureLeads(): void
    {
        if (Schema::hasTable('leads')) {
            return;
        }
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('company')->nullable();
            $t->string('designation')->nullable();
            $t->string('city')->nullable();
            $t->string('mobile')->nullable();
            $t->string('email')->nullable();
            $t->string('employees')->nullable();      // size band, e.g. "26 - 75"
            $t->text('challenges')->nullable();
            $t->string('status')->default('new');     // new|contacted|closed
            $t->text('notes')->nullable();
            $t->string('source')->default('landing');
            $t->timestamps();
        });
    }

    /** PUBLIC: POST /lead — store an enquiry and fire the alerts. */
    public function store(Request $request)
    {
        // Honeypot: real visitors never see/fill this field. Pretend success.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true, 'message' => 'Thank you! Our team will contact you shortly.']);
        }

        $v = $request->validate([
            'name' => 'required|string|max:120',
            'company' => 'required|string|max:160',
            'designation' => 'nullable|string|max:120',
            'city' => 'required|string|max:120',
            'mobile' => 'required|string|max:20',
            'email' => 'required|email|max:160',
            'employees' => 'nullable|string|max:40',
            'challenges' => 'nullable|string|max:2000',
        ]);

        self::ensureLeads();
        $id = DB::table('leads')->insertGetId(array_merge($v, [
            'status' => 'new',
            'source' => 'landing',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $contact = $this->landingContact();

        // Alert 1 — email to the configurable recipient (platform SMTP). Fail-soft.
        try {
            $to = trim((string) ($contact['lead_email'] ?? '')) ?: trim((string) ($contact['email'] ?? ''));
            if ($to !== '') {
                MailService::queue([
                    'tenant_id' => null,
                    'kind' => 'lead',
                    'to' => $to,
                    'subject' => 'New SmartPRS demo request — '.$v['company'],
                    'greeting' => 'New lead from the SmartPRS website',
                    'intro' => $v['name'].' ('.($v['designation'] ?: 'designation not given').') of '.$v['company'].' has requested a demo.',
                    'lines' => array_filter([
                        'Company: '.$v['company'],
                        'City: '.$v['city'],
                        'Mobile: '.$v['mobile'],
                        'Email: '.$v['email'],
                        ($v['employees'] ?? '') !== '' ? 'Employees: '.$v['employees'] : null,
                        ($v['challenges'] ?? '') !== '' ? 'Challenges: '.$v['challenges'] : null,
                        'Lead #'.$id.' — work it at /admin/leads',
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lead email alert failed: '.$e->getMessage());
        }

        // Alert 2 — WhatsApp via Interakt template (needs approval; fail-soft).
        try {
            $wa = preg_replace('/\D+/', '', (string) ($contact['lead_wa'] ?? $contact['whatsapp'] ?? ''));
            if ($wa !== '') {
                WaService::sendTemplate([
                    'mobile' => $wa,
                    'template' => WaService::templateNameFor('lead'),
                    'kind' => 'lead',
                    'bodyValues' => [
                        $v['name'],
                        $v['company'],
                        $v['mobile'],
                        $v['city'],
                        ($v['employees'] ?? '') !== '' ? $v['employees'] : '-',
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lead WhatsApp alert failed: '.$e->getMessage());
        }

        return response()->json(['ok' => true, 'message' => 'Thank you! Our team will contact you shortly.']);
    }

    /** SUPER ADMIN: GET /admin/leads — the lead work-list. */
    public function index(Request $request)
    {
        $this->guard($request);
        self::ensureLeads();
        $status = trim((string) $request->query('status', ''));
        $rows = DB::table('leads')
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->limit(500)->get();
        $counts = DB::table('leads')->selectRaw("status, count(*) n")->groupBy('status')->pluck('n', 'status');

        return view('admin.leads', ['rows' => $rows, 'counts' => $counts, 'status' => $status ?: 'all']);
    }

    /** SUPER ADMIN: POST /admin/leads/{id} — status + notes update. */
    public function update(Request $request, int $id)
    {
        $this->guard($request);
        self::ensureLeads();
        $v = $request->validate([
            'status' => 'required|in:new,contacted,closed',
            'notes' => 'nullable|string|max:2000',
        ]);
        DB::table('leads')->where('id', $id)->update([
            'status' => $v['status'],
            'notes' => $v['notes'] ?? DB::raw('notes'),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.leads', ['status' => $request->query('back', 'all')])->with('success', 'Lead #'.$id.' updated.');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }

    /** The landing 'contact' block (CMS-saved values win over defaults). */
    private function landingContact(): array
    {
        try {
            $c = (new LandingController)->content();

            return is_array($c['contact'] ?? null) ? $c['contact'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
