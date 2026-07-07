<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Serves live database data to the prototype engine, mapped into the shapes the
 * prototype's screens expect. Tenant-scoped: super admin sees all tenants;
 * company users see only their tenant.
 *
 * Statutory rates (PF cap/rate, ESI threshold/rates, PT, TDS slabs, std
 * deduction, 87A, cess, 194H %, no-PAN %) come from SettingsController so
 * payroll, PF/ESIC and TDS math reflect the configured values; per-company
 * branding is surfaced so the UI can re-brand live on company switch.
 */
class AppDataController extends Controller
{
    private const SALARY_TYPE = [
        'only_salary' => 'Salary',
        'salary_commission' => 'Salary + Commission',
        'only_commission' => 'Commission',
    ];

    /**
     * Add the org-hierarchy name columns to employees if missing
     * (department/designation/branch/team/reporting_manager/team_leader), used as
     * editable overrides. Self-creating per project convention; never fatal.
     */
    private static function ensureEmployeeColumns(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        $cols = ['department', 'designation', 'branch', 'team', 'reporting_manager', 'team_leader', 'father', 'spouse', 'blood_group', 'id_marks', 'gender', 'address', 'pt_state'];
        $missing = array_values(array_filter($cols, fn ($c) => ! Schema::hasColumn('employees', $c)));
        if (! $missing) {
            return;
        }
        Schema::table('employees', function (Blueprint $t) use ($missing) {
            foreach ($missing as $c) {
                $t->string($c)->nullable();
            }
        });
    }

    /**
     * rev172 (H3) — recursively strip HTML tags and angle-brackets from every
     * string in an array. Used to sanitise user-entered names / free-text before
     * saving, so stored values can never inject script when later rendered into
     * the SPA via innerHTML. Numbers/bools/keys are left untouched.
     */
    public static function stripHtmlDeep($val)
    {
        if (is_array($val)) {
            return array_map([self::class, 'stripHtmlDeep'], $val);
        }
        if (is_string($val)) {
            return trim(str_replace(['<', '>'], '', strip_tags($val)));
        }

        return $val;
    }

    /** Keep only the array keys that are real columns on $table (schema-tolerant insert). */
    private function onlyExistingCols(string $table, array $row): array
    {
        static $cache = [];
        if (! isset($cache[$table])) {
            $cache[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : array_keys($row);
        }
        $cols = $cache[$table];

        return array_intersect_key($row, array_flip($cols));
    }

    /** Branding map that never throws — branding is cosmetic, must not break /app/data. */
    private function safeBrandingMap(?int $tenantId): array
    {
        try {
            return ConfigController::brandingMap($tenantId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function bootstrap(Request $request)
    {
        $tenantId = $request->user()->tenant_id;   // null = super admin (all tenants)
        $rates = SettingsController::rates($tenantId);
        try {
            self::ensureEmployeeColumns();
        } catch (\Throwable $e) {
            // adding optional hierarchy columns is best-effort; never block bootstrap
        }

        $empQ = DB::table('employees')->whereNull('deleted_at');
        $compQ = DB::table('companies')->whereNull('deleted_at');
        if ($tenantId) {
            $empQ->where('tenant_id', $tenantId);
            $compQ->where('tenant_id', $tenantId);
        }

        $deptNames = DB::table('departments')->pluck('name', 'id');
        $desigNames = DB::table('designations')->pluck('name', 'id');
        $branchNames = DB::table('branches')->pluck('name', 'id');
        $teamRows = DB::table('teams')->get(['id', 'name', 'leader_id']);
        $teamNames = $teamRows->pluck('name', 'id');
        $teamLeaderId = $teamRows->pluck('leader_id', 'id');

        $emps = $empQ->orderBy('emp_code')->get();
        $empNames = $emps->pluck('name', 'id');   // id → name, for manager / leader resolution
        $refsByEmp = DB::table('employee_references')
            ->whereIn('employee_id', $emps->pluck('id'))->get()->groupBy('employee_id');

        $employees = $emps->map(function ($e) use ($deptNames, $desigNames, $branchNames, $teamNames, $teamLeaderId, $empNames, $refsByEmp) {
            $refs = ($refsByEmp[$e->id] ?? collect())->map(fn ($r) => [
                'name' => $r->name, 'relation' => $r->relation, 'aadhaar' => $r->aadhaar,
                'pan' => $r->pan, 'mobile' => $r->mobile,
                'verify' => [
                    'email' => (bool) $r->verify_email, 'sms' => (bool) $r->verify_sms,
                    'call' => (bool) $r->verify_call, 'whatsapp' => (bool) $r->verify_whatsapp,
                ],
            ])->values();

            // Cast to array so any column missing on an older deployed schema
            // (e.g. department_id, branch_id, team_id) reads as null via ?? instead
            // of throwing "Undefined property: stdClass::$...". $col() is the helper.
            $a = (array) $e;
            $col = fn ($k) => $a[$k] ?? null;
            $deptId = $col('department_id');
            $teamId = $col('team_id');
            $mgrId = $col('reporting_manager_id');

            return [
                'id' => $col('emp_code'),
                'refs' => $refs,
                'name' => $col('name'),
                'photo' => $col('photo_path') ? url('/app/emp-photo/'.$col('emp_code')) : '',
                'companyId' => (string) $col('company_id'),
                'companies' => [(string) $col('company_id')],
                // Hierarchy: prefer the editable name column, else resolve the
                // normalized FK (so seeded/existing employees show correctly).
                'dept' => $col('department') ?: ($deptId ? ($deptNames[$deptId] ?? '') : ''),
                'designation' => $col('designation') ?: ($col('designation_id') ? ($desigNames[$col('designation_id')] ?? '') : ''),
                'branch' => $col('branch') ?: ($col('branch_id') ? ($branchNames[$col('branch_id')] ?? '') : ''),
                'team' => $col('team') ?: ($teamId ? ($teamNames[$teamId] ?? '') : ''),
                // Keys match the prototype Add/Edit form field ids (teamManager /
                // teamLeader) so prefill works; reporting/leader kept as aliases.
                'teamManager' => $col('reporting_manager') ?: ($mgrId ? ($empNames[$mgrId] ?? '') : ''),
                'teamLeader' => $col('team_leader') ?: (($teamId && ($teamLeaderId[$teamId] ?? null)) ? ($empNames[$teamLeaderId[$teamId]] ?? '') : ''),
                'reporting' => $col('reporting_manager') ?: ($mgrId ? ($empNames[$mgrId] ?? '') : ''),
                'leader' => $col('team_leader') ?: (($teamId && ($teamLeaderId[$teamId] ?? null)) ? ($empNames[$teamLeaderId[$teamId]] ?? '') : ''),
                'type' => $col('type') === 'field' ? 'Field / FOS' : 'Office',
                'doj' => $col('doj') ?? '',
                'mobile' => $col('mobile') ?? '',
                'email' => $col('email') ?? '',
                'ctc' => (float) $col('ctc'),
                'pf' => $col('pf_applicable') ? 'Yes' : 'No',
                'esi' => $col('esi_applicable') === 'yes' ? 'Yes' : 'No',
                'pan' => $col('pan') ?? '',
                'uan' => $col('uan') ?? '',
                'ptState' => $col('pt_state') ?? '',
                'bankName' => $col('bank_name') ?? '',
                'bankAcc' => $col('bank_acc') ?? '',
                'ifsc' => $col('ifsc') ?? '',
                'status' => ucfirst((string) $col('status')),
                'salaryType' => self::SALARY_TYPE[$col('salary_type')] ?? 'Salary',
                'commPct' => (float) ($col('comm_pct') ?? 0),
            ];
        })->values();

        $companies = $compQ->orderBy('name')->get()
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])->values();

        // SaaS Platform → Tenants (super admin only).
        $tenants = collect();
        if (! $tenantId) {
            $planNames = DB::table('plans')->pluck('name', 'id');
            $tenants = DB::table('tenants')->whereNull('deleted_at')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->uuid ?: (string) $t->id,
                'name' => $t->name,
                'plan' => $t->plan_id ? ($planNames[$t->plan_id] ?? '—') : '—',
                'status' => ucfirst($t->status),
                'seatsUsed' => (int) $t->seats_used,
                'seatsLicensed' => (int) $t->seats_licensed,
                'mrr' => (float) $t->mrr,
                'deployment' => $t->deployment === 'saas' ? 'SaaS' : 'On-Premise',
                'signup' => $t->created_at ? \Illuminate\Support\Carbon::parse($t->created_at)->format('d M Y') : '',
            ])->values();
        }

        // Company in scope for payroll generation (first of the tenant's companies).
        $companyId = DB::table('companies')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')->value('id');

        // Payroll history — last 12 months into normalized tables (idempotent).
        // Wrapped: a payroll-table schema mismatch must never 500 /app/data (that
        // blanks the whole app and aborts the boot script → logout/nav vanish).
        try {
            $payroll = $this->ensurePayrollHistory($tenantId, $companyId, $emps, $rates);
        } catch (\Throwable $e) {
            $payroll = ['runs' => collect(), 'payslips' => collect()];
        }

        // rev149 — org-hierarchy option lists for the Add/Edit forms' dropdowns
        // (Department / Branch / Designation / Team). Without these the in-browser
        // option arrays are empty on a fresh install, so the selects show nothing
        // even though the records exist (visible on their own list screens).
        $optList = function (string $table) use ($tenantId) {
            try {
                if (! Schema::hasTable($table)) {
                    return collect();
                }

                return DB::table($table)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->when($tenantId && Schema::hasColumn($table, 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])->values();
            } catch (\Throwable $e) {
                return collect();
            }
        };

        return response()->json([
            'employees' => $employees,
            'companies' => $companies,
            'branches' => $optList('branches'),
            'departments' => $optList('departments'),
            'designations' => $optList('designations'),
            'teams' => $optList('teams'),
            'tenants' => $tenants,
            'payrollRuns' => $payroll['runs'],
            'payslips' => $payroll['payslips'],
            'tdsReturns' => $this->tdsReturnsHistory($emps, $rates),
            'rates' => $rates,
            // Per-company branding map (companyId → {display_name,color,logo,tagline})
            // so the frontend can re-brand live when the company switcher changes.
            'branding' => $this->safeBrandingMap($tenantId),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    /** Bulk import employees from a CSV upload into the real employees table. */
    public function importEmployees(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        // rev154 — validate by EXTENSION, not MIME. A .csv saved by Excel is often
        // reported with a non-text MIME (application/vnd.ms-excel, octet-stream,
        // etc.), and `mimes:csv,txt` then rejected a perfectly valid file — which
        // looked like "the upload does nothing" on the client. We only need a real
        // uploaded file ending in .csv/.txt; the parser handles the rest.
        $request->validate(['file' => ['required', 'file']]);
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt'], true)) {
            return response()->json(['ok' => false, 'error' => 'Please upload the sample file as .csv (saved from Excel as "CSV UTF-8"). Got: .'.$ext], 422);
        }

        $user = $request->user();
        $tenantId = $user->tenant_id ?? DB::table('tenants')->value('id');
        $companyId = DB::table('companies')
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))->value('id');

        $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return response()->json(['ok' => false, 'error' => 'Empty file'], 422);
        }
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));

        $salaryMap = ['salary' => 'only_salary', 'salary + commission' => 'salary_commission', 'commission' => 'only_commission'];

        // rev149 — make sure the richer template's columns exist on the employees
        // table (self-creating, per project convention) so they actually import.
        // rev154 — guarded: the columns are normally guaranteed by migration; if a
        // restricted DB user can't ALTER, the import must still proceed (extra
        // fields are written only when their column exists, see Schema::hasColumn
        // below), never 500 silently.
        try {
            self::ensureEmployeeColumns();
        } catch (\Throwable $e) {
        }
        try {
            if (Schema::hasTable('employees')) {
                foreach (['whatsapp', 'address', 'dob', 'doj'] as $c) {
                    if (! Schema::hasColumn('employees', $c)) {
                        Schema::table('employees', function (Blueprint $t) use ($c) {
                            $t->string($c)->nullable();
                        });
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $count = 0;
        foreach ($lines as $line) {
            $cells = str_getcsv($line);
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad($cells, count($header), null));
            $row = self::stripHtmlDeep($row); // rev172 (H3) — sanitise imported free-text against stored XSS
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $code = trim((string) ($row['emp_code'] ?? $row['code'] ?? '')) ?: ('EMP-'.random_int(1000, 9999));
            $type = stripos((string) ($row['type'] ?? ''), 'field') !== false ? 'field' : 'office';

            $payload = [
                'tenant_id' => $tenantId, 'company_id' => $companyId, 'emp_code' => $code, 'name' => $name,
                'type' => $type, 'ctc' => (float) ($row['ctc'] ?? 0),
                'salary_type' => $salaryMap[strtolower(trim((string) ($row['salary_type'] ?? 'salary')))] ?? 'only_salary',
                'mobile' => $row['mobile'] ?? null, 'email' => $row['email'] ?? null, 'pan' => $row['pan'] ?? null,
                'uan' => $row['uan'] ?? ($row['pf_number'] ?? ($row['pf'] ?? null)),
                'bank_acc' => $row['bank_acc'] ?? null, 'ifsc' => $row['ifsc'] ?? null, 'updated_at' => now(),
            ];
            // rev149 — richer fields from the full template (only set when present;
            // the columns were ensured above, so these are schema-safe).
            $extra = [
                'department' => trim((string) ($row['department'] ?? '')) ?: null,
                'designation' => trim((string) ($row['designation'] ?? '')) ?: null,
                'branch' => trim((string) ($row['branch'] ?? '')) ?: null,
                'team' => trim((string) ($row['team'] ?? '')) ?: null,
                'whatsapp' => trim((string) ($row['whatsapp'] ?? ($row['whatsapp_number'] ?? ''))) ?: null,
                'address' => trim((string) ($row['address'] ?? '')) ?: null,
                'dob' => trim((string) ($row['dob'] ?? ($row['date_of_birth'] ?? ''))) ?: null,
                'doj' => trim((string) ($row['doj'] ?? ($row['date_of_joining'] ?? ''))) ?: null,
            ];
            foreach ($extra as $k => $v) {
                if ($v !== null && Schema::hasColumn('employees', $k)) {
                    $payload[$k] = $v;
                }
            }
            // Resolve the Company name to a company_id within this tenant.
            $compName = trim((string) ($row['company'] ?? ''));
            if ($compName !== '') {
                $cid = DB::table('companies')
                    ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [strtolower($compName)])->value('id');
                if ($cid) {
                    $payload['company_id'] = $cid;
                }
            }

            $existing = DB::table('employees')->where('tenant_id', $tenantId)->where('emp_code', $code)->first();
            if ($existing) {
                DB::table('employees')->where('id', $existing->id)->update($payload);
            } else {
                // SEAT LIMIT (rev 75): stop importing NEW rows once the subscribed
                // seat count is reached (updates to existing employees still apply).
                $seat = \App\Services\SubscriptionService::canAddEmployees($user->tenant_id ? (int) $user->tenant_id : null, 1);
                if (! $seat['ok']) {
                    return response()->json([
                        'ok' => $count > 0,
                        'count' => $count,
                        'error' => 'Imported '.$count.' row(s), then stopped. '.$seat['error'],
                    ], $count > 0 ? 200 : 422);
                }
                $payload['uuid'] = (string) Str::uuid();
                $payload['status'] = 'active';
                $payload['created_at'] = now();
                DB::table('employees')->insert($payload);
            }

            // rev149 — optional self-service login from the template's password
            // column. Guarded so a login problem can never break the import.
            $pwd = trim((string) ($row['password'] ?? ''));
            $loginEmail = trim((string) ($row['email'] ?? ''));
            if ($pwd !== '' && $loginEmail !== '' && Schema::hasTable('users')) {
                try {
                    $u = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($loginEmail)])
                        ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->first();
                    if ($u) {
                        DB::table('users')->where('id', $u->id)->update(['password' => bcrypt($pwd), 'updated_at' => now()]);
                    } else {
                        $uid = DB::table('users')->insertGetId([
                            'tenant_id' => $tenantId, 'name' => $name, 'email' => $loginEmail,
                            'password' => bcrypt($pwd), 'status' => 'active',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        try {
                            $um = \App\Models\User::find($uid);
                            if ($um && method_exists($um, 'syncRoles')) {
                                $um->syncRoles(['employee']);
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
            $count++;
        }

        return response()->json(['ok' => true, 'count' => $count]);
    }

    /**
     * rev 82c: bulk SOFT delete (Ejaz — select/bulk-select on ID Cards).
     * Admin only; deleted_at is set so history (payslips, attendance, ledgers)
     * stays intact while the person disappears from every screen and the seat
     * count frees up. Real exits should use Exit & FnF.
     */
    public function bulkDeleteEmployees(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin'])) {
            return $deny;
        }
        try {
            $v = $request->validate(['codes' => ['required', 'array', 'min:1', 'max:500'], 'codes.*' => ['string']]);
            $tid = $request->user()->tenant_id;
            $n = DB::table('employees')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->whereIn('emp_code', $v['codes'])
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return response()->json(['ok' => true, 'deleted' => $n, 'message' => $n.' employee record(s) removed (history kept, seats freed).']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Download a CSV import template. */
    public function employeeTemplate()
    {
        // rev149 — full template: company / department / designation / branch / team
        // / dates / WhatsApp / address / password are now included and imported.
        $head = 'emp_code,name,type,company,department,designation,branch,team,doj,dob,mobile,whatsapp,address,email,ctc,salary_type,pan,uan,bank_acc,ifsc,password';
        $csv = $head."\n"
            .'EMP100,Sample Name,office,Acme Recovery Pvt Ltd,Operations,Executive,Head Office,Alpha Team,2024-04-01,1995-06-15,+919999999999,+919999999999,"12 MG Road, Hyderabad",sample@company.in,600000,Salary,ABCDE1234F,100200300400,12345678901,SBIN0001234,Welcome@123'."\n"
            .'EMP101,Field Agent Name,field,Acme Recovery Pvt Ltd,Collections,Field Officer,Branch-2,Bravo Team,2024-05-10,1998-02-20,+918888888888,+918888888888,"45 Park Street, Pune",agent@company.in,336000,Salary + Commission,FGHIJ5678K,100200300401,10987654321,HDFC0005678,Welcome@123'."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="smartprs-employee-import-template.csv"',
        ]);
    }

    /** Download a branded PDF payslip for an employee (computed from CTC). */
    public function payslipPdf(Request $request, string $code)
    {
        // Scope: a company user is limited to their tenant; a super-admin (null
        // tenant_id) can resolve an employee in ANY tenant/company — don't fall
        // back to "first tenant only", which 404s for everyone else's staff.
        $userTenant = $request->user()->tenant_id;
        $e = DB::table('employees')->where('emp_code', $code)
            ->when($userTenant, fn ($q) => $q->where('tenant_id', $userTenant))
            ->whereNull('deleted_at')->first();

        if (! $e) {
            // Friendly message instead of a blank 404 page. Most common cause:
            // the Directory is still showing prototype demo rows that were never
            // saved to the database (run the seeder / add the employee for real).
            return response(
                'Payslip not available: employee "'.e($code).'" was not found in the database. '
                .'If you can see them in the Directory but this fails, their record is demo/sample data that has not been saved yet — '
                .'add them via Add Employee (or run the demo seeder) and try again.',
                404
            )->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $company = DB::table('companies')->find($e->company_id);
        $month = $request->query('month', now()->format('Y-m'));
        $rates = SettingsController::rates($e->tenant_id);

        // rev172 — non-HR users may download ONLY their own payslip, and only if
        // the company's payslip download policy allows it (Payslips → Download
        // Policy). HR/Admin can always download (and email) any payslip.
        $user = $request->user();
        if (! $user->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
            $own = ! empty($user->employee_id)
                ? ((int) $user->employee_id === (int) $e->id)
                : (($e->email ?? '') !== '' && strcasecmp((string) $user->email, (string) $e->email) === 0)
                    || strcasecmp((string) $user->name, (string) $e->name) === 0;
            if (! $own) {
                return response('You can only download your own payslip.', 403)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }
            if (! SettingsController::payslipSelfAllowed($e, $rates)) {
                return response('Payslip download is disabled by your company\'s policy. Please contact HR for a copy.', 403)
                    ->header('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        $data = $this->payslipViewData($e, $company, $month, $rates);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('payslip-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->download('payslip-'.$code.'-'.$month.'.pdf');
    }

    /**
     * Render the payslip PDF for an employee id + month as raw bytes, for emailing
     * (e.g. attached on disbursement). Best-effort — returns null on any failure so
     * a mail problem never blocks the salary action.
     */
    public function payslipPdfString(int $employeeId, string $month): ?string
    {
        try {
            $e = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->first();
            if (! $e) {
                return null;
            }
            $company = DB::table('companies')->find($e->company_id);
            $rates = SettingsController::rates($e->tenant_id);
            $data = $this->payslipViewData($e, $company, $month, $rates);

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('payslip-pdf', $data)
                ->setPaper('a4', 'portrait')->output();
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Build the full view-data array for the payslip PDF (prefers the stored slip
     * for the month; falls back to a full-CTC computation). Shared by the download
     * route and the email attachment. READ-ONLY — recomputes nothing stored.
     */
    private function payslipViewData($e, $company, string $month, array $rates): array
    {
        // Prefer the actual generated payslip for this month (it carries the real
        // proration, late/break cuts and the calculation note); fall back to a
        // full-CTC computation if no run exists yet.
        $slipRow = DB::table('payslips')
            ->where('employee_id', $e->id)->where('month', $month)
            ->orderByDesc('id')->first();
        $note = null;
        $earnMap = null;
        $dedMap = null;
        if ($slipRow) {
            $earn = json_decode($slipRow->earnings ?: '{}', true) ?: [];
            $ded = json_decode($slipRow->deductions ?: '{}', true) ?: [];
            $earnMap = $earn;
            $dedMap = $ded;
            $s = [
                'gross' => (float) $slipRow->gross,
                'basic' => (float) ($earn['Basic'] ?? 0),
                'hra' => (float) ($earn['HRA'] ?? 0),
                'special' => (float) ($earn['Special Allowance'] ?? 0),
                'commission' => (float) ($earn['Commission'] ?? 0),
                'pf' => (float) ($ded['PF'] ?? 0),
                'esi' => (float) ($ded['ESI'] ?? 0),
                'pt' => (float) ($ded['Professional Tax'] ?? 0),
                'tds' => (float) ($ded['TDS'] ?? 0),
                'total_ded' => (float) $slipRow->total_ded,
                'net' => (float) $slipRow->net,
            ];
            $note = Schema::hasColumn('payslips', 'calc_note') ? ($slipRow->calc_note ?? null) : null;
        } else {
            $s = self::computeSlip((float) $e->ctc, $rates);
        }

        // --- rev162 (Payslip Phase 1, DISPLAY-ONLY): richer, A4 payslip metadata.
        // Everything below is READ-ONLY and additive. It never changes net pay or
        // any stored figure; it only enriches what the PDF prints.
        $emp = $this->payslipEmployeeMeta($e);
        $employer = self::payslipEmployerCost($s, $rates);
        $payslipId = 'PRS/'.str_replace('-', '', $month).'/'.$e->emp_code;

        // Paid days / LOP — read from the calc note if it recorded proration,
        // otherwise assume a full month (no LOP).
        $totalDays = \Illuminate\Support\Carbon::parse($month.'-01')->daysInMonth;
        $paidDays = $totalDays;
        $lopDays = 0;
        if ($note && preg_match('/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i', $note, $pm)) {
            $paidDays = (float) $pm[1];
            $totalDays = (int) $pm[2];
            $lopDays = round($totalDays - $paidDays, 1);
        }

        // Approved leave taken this financial-year, compact per type. Defensive.
        $leaveStr = '';
        try {
            if (Schema::hasTable('leaves')) {
                $yr = (int) substr($month, 0, 4);
                $lrows = DB::table('leaves')->where('employee_id', $e->id)
                    ->where('status', 'approved')->whereYear('from_date', $yr)
                    ->get(['type_name', 'days']);
                $agg = [];
                foreach ($lrows as $lr) {
                    $k = $lr->type_name ?: 'Leave';
                    $agg[$k] = ($agg[$k] ?? 0) + (float) $lr->days;
                }
                $parts = [];
                foreach ($agg as $k => $v) {
                    $parts[] = $k.' '.rtrim(rtrim(number_format($v, 1), '0'), '.');
                }
                $leaveStr = implode('  ·  ', $parts);
            }
        } catch (\Throwable $ex) {
            $leaveStr = '';
        }

        // --- P2 (Fixed/Variable/Reimbursement grouping) + P3 (financial-year-to-date).
        // DISPLAY-ONLY: read-only classification + aggregation; no figure is recomputed.
        $earnForGroup = $earnMap ?: array_filter([
            'Basic' => (float) ($s['basic'] ?? 0),
            'HRA' => (float) ($s['hra'] ?? 0),
            'Special Allowance' => (float) ($s['special'] ?? 0),
            'Commission' => (float) ($s['commission'] ?? 0),
        ], fn ($v) => $v != 0.0);
        $dedForShow = $dedMap ?: array_filter([
            'PF' => (float) ($s['pf'] ?? 0),
            'ESI' => (float) ($s['esi'] ?? 0),
            'Professional Tax' => (float) ($s['pt'] ?? 0),
            'TDS' => (float) ($s['tds'] ?? 0),
            'Labour Welfare Fund' => (float) ($s['lwf'] ?? 0),
            'Conveyance' => (float) ($s['conveyance'] ?? 0),
        ], fn ($v) => $v != 0.0);

        $ytd = $this->payslipYtd((int) $e->id, $month);
        $grouped = $this->payslipGroupEarnings($e, $earnForGroup, $ytd['earn'] ?? []);
        $dedLines = [];
        foreach ($dedForShow as $dn => $dv) {
            $dedLines[] = ['name' => $dn, 'amt' => (float) $dv, 'ytd' => (float) ($ytd['ded'][$dn] ?? 0)];
        }

        return [
            'e' => $e,
            'company' => $company,
            'brand' => ConfigController::brandFor($e->tenant_id, $e->company_id),
            'month' => $month,
            'monthLabel' => \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y'),
            's' => $s,
            'note' => $note,
            'earnMap' => $earnMap,
            'dedMap' => $dedMap,
            'emp' => $emp,
            'employer' => $employer,
            'payslipId' => $payslipId,
            'paidDays' => $paidDays,
            'lopDays' => $lopDays,
            'totalDays' => $totalDays,
            'leaveStr' => $leaveStr,
            'grouped' => $grouped,
            'dedLines' => $dedLines,
            'ytd' => $ytd,
            'netWords' => self::amountInWords((float) ($s['net'] ?? 0)),
        ];
    }

    /** "Rupees Twenty Thousand One Hundred One Only" (Indian numbering, incl. paise). */
    public static function amountInWords(float $amount): string
    {
        $amount = round(max($amount, 0), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $out = 'Rupees '.self::numToWordsIndian($rupees);
        if ($paise > 0) {
            $out .= ' and '.self::numToWordsIndian($paise).' Paise';
        }

        return $out.' Only';
    }

    /** Integer → words with Indian grouping (crore / lakh / thousand / hundred). */
    private static function numToWordsIndian(int $n): string
    {
        if ($n <= 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = function (int $num) use ($ones, $tens) {
            if ($num < 20) {
                return $ones[$num];
            }
            $t = intdiv($num, 10);
            $o = $num % 10;

            return trim($tens[$t].($o ? ' '.$ones[$o] : ''));
        };
        $three = function (int $num) use ($ones, $two) {
            $h = intdiv($num, 100);
            $rest = $num % 100;
            $s = $h ? $ones[$h].' Hundred' : '';
            if ($rest) {
                $s .= ($s ? ' ' : '').$two($rest);
            }

            return $s;
        };
        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $hundred = $n % 1000;
        $parts = [];
        if ($crore) {
            $parts[] = $three($crore).' Crore';
        }
        if ($lakh) {
            $parts[] = $two($lakh).' Lakh';
        }
        if ($thousand) {
            $parts[] = $two($thousand).' Thousand';
        }
        if ($hundred) {
            $parts[] = $three($hundred);
        }

        return implode(' ', $parts);
    }

    /**
     * Resolve display names for the payslip header (designation / department /
     * branch / DOJ). READ-ONLY — used only by the PDF. Prefers a string column
     * if present, else looks up the *_id reference table; never throws.
     */
    private function payslipEmployeeMeta($e): array
    {
        $name = function (string $strCol, string $idCol, string $table) use ($e) {
            $v = property_exists($e, $strCol) ? trim((string) ($e->$strCol ?? '')) : '';
            if ($v !== '') {
                return $v;
            }
            $id = property_exists($e, $idCol) ? ($e->$idCol ?? null) : null;
            if ($id) {
                try {
                    return (string) (DB::table($table)->where('id', $id)->value('name') ?? '—');
                } catch (\Throwable $ex) {
                    return '—';
                }
            }
            return '—';
        };
        $doj = (property_exists($e, 'doj') && $e->doj)
            ? \Illuminate\Support\Carbon::parse($e->doj)->format('d M Y') : '—';

        return [
            'designation' => $name('designation', 'designation_id', 'designations'),
            'department' => $name('department', 'department_id', 'departments'),
            'branch' => $name('branch', 'branch_id', 'branches'),
            'doj' => $doj,
            'type' => ucfirst((string) ($e->type ?? '')),
        ];
    }

    /**
     * Indicative EMPLOYER cost for the CTC memo block (employer PF, employer ESI,
     * EDLI, gratuity accrual, and total monthly CTC). READ-ONLY and NEVER added
     * to employee deductions. Uses exact figures from computeSlip when available,
     * otherwise derives them from the stored employee PF/ESI so the memo always
     * stays consistent with the deductions actually shown.
     */
    public static function payslipEmployerCost(array $s, ?array $rates = null): array
    {
        $r = $rates ?: SettingsController::defaults();
        $basic = (float) ($s['basic'] ?? 0);
        $empPf = isset($s['pf_employer']) ? (float) $s['pf_employer'] : (float) ($s['pf'] ?? 0);
        $eeRate = (float) ($r['esi_employee_rate'] ?? 0.75);
        $erRate = (float) ($r['esi_employer_rate'] ?? 3.25);
        $empEsi = isset($s['esi_employer'])
            ? (float) $s['esi_employer']
            : ($eeRate > 0 ? round((float) ($s['esi'] ?? 0) * $erRate / $eeRate, 2) : 0.0);
        $edli = isset($s['pf_edli']) ? (float) $s['pf_edli'] : round(min($basic, 15000.0) * 0.5 / 100, 2);
        $gratuity = round($basic * 4.81 / 100, 2);
        $ctc = round((float) ($s['gross'] ?? 0) + $empPf + $empEsi + $edli + $gratuity, 2);

        return [
            'pf' => round($empPf, 2), 'esi' => round($empEsi, 2),
            'edli' => round($edli, 2), 'gratuity' => $gratuity, 'ctc' => $ctc,
        ];
    }

    /**
     * Financial-year-to-date (Apr→current month) totals per earning/deduction plus
     * gross/deductions/net, aggregated from stored payslips. READ-ONLY. Month keys
     * are 'YYYY-MM' (zero-padded) so lexicographic range compare is correct.
     */
    private function payslipYtd(int $empId, string $month): array
    {
        $out = ['earn' => [], 'ded' => [], 'gross' => 0.0, 'ded_total' => 0.0, 'net' => 0.0];
        try {
            $y = (int) substr($month, 0, 4);
            $mm = (int) substr($month, 5, 2);
            $fyStart = sprintf('%04d-04', $mm >= 4 ? $y : $y - 1);
            $rows = DB::table('payslips')->where('employee_id', $empId)
                ->where('month', '>=', $fyStart)->where('month', '<=', $month)
                ->get(['earnings', 'deductions', 'gross', 'total_ded', 'net']);
            foreach ($rows as $row) {
                $en = json_decode($row->earnings ?: '{}', true) ?: [];
                $dd = json_decode($row->deductions ?: '{}', true) ?: [];
                foreach ($en as $k => $v) {
                    $out['earn'][$k] = ($out['earn'][$k] ?? 0) + (float) $v;
                }
                foreach ($dd as $k => $v) {
                    $out['ded'][$k] = ($out['ded'][$k] ?? 0) + (float) $v;
                }
                $out['gross'] += (float) $row->gross;
                $out['ded_total'] += (float) $row->total_ded;
                $out['net'] += (float) $row->net;
            }
        } catch (\Throwable $ex) {
        }

        return $out;
    }

    /**
     * Classify earning lines into Fixed / Variable / Reimbursement for the grouped
     * payslip. Uses the component's Category (from salary_components) when set, else
     * a name heuristic. READ-ONLY. Returns groups[] + subtotals[].
     */
    private function payslipGroupEarnings($e, array $earnMap, array $ytdEarn): array
    {
        $catMap = [];
        try {
            $rows = DB::table('salary_components')
                ->when(property_exists($e, 'tenant_id') && $e->tenant_id, fn ($q) => $q->where('tenant_id', $e->tenant_id))
                ->get(['code', 'name', 'category']);
            foreach ($rows as $rc) {
                $cat = strtolower(trim((string) ($rc->category ?? '')));
                if ($cat === '') {
                    continue;
                }
                if (! empty($rc->name)) {
                    $catMap[strtolower(trim($rc->name))] = $cat;
                }
                if (! empty($rc->code)) {
                    $catMap[strtolower(trim($rc->code))] = $cat;
                }
            }
        } catch (\Throwable $ex) {
        }
        $classify = function (string $name) use ($catMap) {
            $lc = strtolower(trim($name));
            if (isset($catMap[$lc]) && in_array($catMap[$lc], ['fixed', 'variable', 'reimbursement'], true)) {
                return $catMap[$lc];
            }
            if (str_contains($lc, 'reimburs')) {
                return 'reimbursement';
            }
            foreach (['commission', 'incentive', 'bonus', 'overtime', 'arrear', 'ex-gratia', 'ex gratia', 'payout', 'variable'] as $kw) {
                if (str_contains($lc, $kw)) {
                    return 'variable';
                }
            }
            return 'fixed';
        };
        $groups = ['fixed' => [], 'variable' => [], 'reimbursement' => []];
        $sub = ['fixed' => 0.0, 'variable' => 0.0, 'reimbursement' => 0.0];
        foreach ($earnMap as $name => $amt) {
            $g = $classify((string) $name);
            $groups[$g][] = ['name' => $name, 'amt' => (float) $amt, 'ytd' => (float) ($ytdEarn[$name] ?? 0)];
            $sub[$g] += (float) $amt;
        }

        return ['groups' => $groups, 'sub' => $sub];
    }

    /** Statutory reports (PF / ESI challans, TDS statement) as downloadable PDFs. */
    public function statutoryPdf(Request $request, string $type)
    {
        $tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id');
        $rates = SettingsController::rates($request->user()->tenant_id);
        $emps = DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('emp_code')->get();
        $company = DB::table('companies')->where('tenant_id', $tenantId)->first();
        $period = now()->format('F Y');
        $n2 = fn ($v) => '₹'.number_format($v, 2);

        $rows = [];
        $sum = [];
        $addSum = function (&$sum, $k, $v) { $sum[$k] = ($sum[$k] ?? 0) + $v; };

        $esiEeRate = (float) $rates['esi_employee_rate'] / 100;
        $esiErRate = (float) $rates['esi_employer_rate'] / 100;
        $esiThreshold = (float) $rates['esi_threshold'];
        $commRate = (float) $rates['comm_tds_rate'] / 100;
        $noPanRate = (float) $rates['no_pan_tds_rate'] / 100;

        if ($type === 'pf') {
            $title = 'PF ECR / Challan';
            $subtitle = 'Provident Fund contributions (employee '.((float) $rates['pf_rate']).'% + employer '.((float) $rates['pf_rate']).'%)';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Employee'], ['k' => 'uan', 'l' => 'UAN (PF No.)'],
                ['k' => 'basic', 'l' => 'PF Wages', 'amt' => true],
                ['k' => 'ee', 'l' => 'Employee', 'amt' => true],
                ['k' => 'er', 'l' => 'Employer', 'amt' => true],
                ['k' => 'total', 'l' => 'Total', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $s = self::computeSlip((float) $e->ctc, $rates);
                $wage = min($s['basic'], (float) $rates['pf_wage_cap']);
                $ee = $s['pf']; $er = $ee; $tot = $ee + $er;
                $addSum($sum, 'basic', $wage); $addSum($sum, 'ee', $ee); $addSum($sum, 'er', $er); $addSum($sum, 'total', $tot);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'uan' => $e->uan ?: '—', 'basic' => $n2($wage), 'ee' => $n2($ee), 'er' => $n2($er), 'total' => $n2($tot)];
            }
        } elseif ($type === 'esi') {
            $title = 'ESIC Challan';
            $subtitle = 'ESI contributions (employee '.((float) $rates['esi_employee_rate']).'% + employer '.((float) $rates['esi_employer_rate']).'%, gross ≤ '.$n2($esiThreshold).')';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Employee'],
                ['k' => 'gross', 'l' => 'Gross', 'amt' => true],
                ['k' => 'ee', 'l' => 'Employee', 'amt' => true],
                ['k' => 'er', 'l' => 'Employer', 'amt' => true],
                ['k' => 'total', 'l' => 'Total', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $s = self::computeSlip((float) $e->ctc, $rates);
                $elig = $s['gross'] <= $esiThreshold;
                $ee = $elig ? round($s['gross'] * $esiEeRate, 2) : 0;
                $er = $elig ? round($s['gross'] * $esiErRate, 2) : 0;
                $tot = $ee + $er;
                $addSum($sum, 'gross', $s['gross']); $addSum($sum, 'ee', $ee); $addSum($sum, 'er', $er); $addSum($sum, 'total', $tot);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'gross' => $n2($s['gross']), 'ee' => $n2($ee), 'er' => $n2($er), 'total' => $n2($tot)];
            }
        } elseif ($type === 'commtds') {
            $title = 'Commission TDS — Deductee Register (Sec 194H)';
            $subtitle = 'Commission/brokerage TDS @ '.((float) $rates['comm_tds_rate']).'% (no-PAN @ '.((float) $rates['no_pan_tds_rate']).'%) — deductee-wise details for Form 26Q';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Deductee'], ['k' => 'pan', 'l' => 'PAN'],
                ['k' => 'section', 'l' => 'Section'],
                ['k' => 'comm', 'l' => 'Commission', 'amt' => true],
                ['k' => 'rate', 'l' => 'Rate'],
                ['k' => 'tds', 'l' => 'TDS Deducted', 'amt' => true],
                ['k' => 'net', 'l' => 'Net Paid', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $st = self::SALARY_TYPE[$e->salary_type] ?? 'Salary';
                $annual = (float) $e->ctc;
                $commBase = $st === 'Commission' ? $annual : ($st === 'Salary + Commission' ? $annual * (((float) ($e->comm_pct ?? 0) ?: 30) / 100) : 0);
                if ($commBase <= 0) {
                    continue;
                }
                $effRate = $e->pan ? $commRate : max($commRate, $noPanRate);
                $tds = round($commBase * $effRate, 2);
                $addSum($sum, 'comm', $commBase); $addSum($sum, 'tds', $tds); $addSum($sum, 'net', $commBase - $tds);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'pan' => $e->pan ?: 'No PAN', 'section' => '194H', 'comm' => $n2($commBase), 'rate' => round($effRate * 100, 2).'%', 'tds' => $n2($tds), 'net' => $n2($commBase - $tds)];
            }
        } else { // tds
            $title = 'TDS Statement (Form 24Q)';
            $subtitle = 'Salary TDS (Sec 192, new regime) + Commission TDS (Sec 194H @ '.((float) $rates['comm_tds_rate']).'%)';
            $columns = [
                ['k' => 'code', 'l' => 'Code'], ['k' => 'name', 'l' => 'Employee'], ['k' => 'paytype', 'l' => 'Pay Type'],
                ['k' => 'saltds', 'l' => 'Salary TDS (192)', 'amt' => true],
                ['k' => 'commtds', 'l' => 'Comm. TDS (194H)', 'amt' => true],
                ['k' => 'tax', 'l' => 'Total TDS', 'amt' => true],
                ['k' => 'monthly', 'l' => 'Monthly', 'amt' => true],
            ];
            foreach ($emps as $e) {
                $annual = (float) $e->ctc;
                $st = self::SALARY_TYPE[$e->salary_type] ?? 'Salary';
                $commBase = $st === 'Commission' ? $annual : ($st === 'Salary + Commission' ? $annual * (((float) ($e->comm_pct ?? 0) ?: 30) / 100) : 0);
                $salBase = max(0, $annual - $commBase);
                $salTds = self::newRegimeTax(max(0, $salBase - (float) $rates['std_deduction']), $rates);
                $effRate = $e->pan ? $commRate : max($commRate, $noPanRate);
                $commTds = round($commBase * $effRate, 2);
                $tax = round($salTds + $commTds, 2);
                $monthly = round($tax / 12, 2);
                $addSum($sum, 'saltds', $salTds); $addSum($sum, 'commtds', $commTds); $addSum($sum, 'tax', $tax); $addSum($sum, 'monthly', $monthly);
                $rows[] = ['code' => $e->emp_code, 'name' => $e->name, 'paytype' => $st, 'saltds' => $n2($salTds), 'commtds' => $n2($commTds), 'tax' => $n2($tax), 'monthly' => $n2($monthly)];
            }
        }

        $totals = ['name' => 'TOTAL ('.count($rows).')'];
        foreach ($sum as $k => $v) { $totals[$k] = $n2($v); }

        $brand = ConfigController::brandFor($request->user()->tenant_id, $company->id ?? null);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('statutory-pdf', compact('title', 'subtitle', 'columns', 'rows', 'totals', 'company', 'period', 'brand'));

        return $pdf->download(strtoupper($type).'-'.now()->format('Y-m').'.pdf');
    }

    /** New-regime annual income tax incl. 87A rebate and cess, from configurable slabs. */
    private static function newRegimeTax(float $taxable, ?array $rates = null): float
    {
        $r = $rates ?: SettingsController::defaults();
        if ($taxable <= (float) $r['rebate_87a_limit']) { return 0.0; } // 87A rebate
        $tax = 0; $prev = 0;
        foreach ($r['tds_slabs'] as $slab) {
            $upto = (float) ($slab['upto'] ?? 0);
            $rate = (float) ($slab['rate'] ?? 0) / 100;
            $cap = $upto > 0 ? $upto : PHP_INT_MAX;
            if ($taxable > $prev) { $tax += (min($taxable, $cap) - $prev) * $rate; $prev = $cap; }
            if ($upto <= 0) { break; }
        }

        return round($tax * (1 + (float) $r['cess_rate'] / 100), 2);
    }

    /** Quarter-wise TDS return (24Q) history with filing status. */
    private function tdsReturnsHistory($emps, ?array $rates = null): array
    {
        $r = $rates ?: SettingsController::defaults();
        $commRate = (float) $r['comm_tds_rate'] / 100;
        $noPanRate = (float) $r['no_pan_tds_rate'] / 100;
        $totalAnnual = 0; $deductees = 0;
        foreach ($emps as $e) {
            $annual = (float) $e->ctc;
            $st = self::SALARY_TYPE[$e->salary_type] ?? 'Salary';
            $commBase = $st === 'Commission' ? $annual : ($st === 'Salary + Commission' ? $annual * (((float) ($e->comm_pct ?? 0) ?: 30) / 100) : 0);
            $salTds = self::newRegimeTax(max(0, ($annual - $commBase) - (float) $r['std_deduction']), $r);
            $effRate = $e->pan ? $commRate : max($commRate, $noPanRate);
            $t = $salTds + round($commBase * $effRate, 2);
            if ($t > 0) { $deductees++; }
            $totalAnnual += $t;
        }
        $perQ = round($totalAnnual / 4, 2);
        $labels = [4 => 'Q1', 7 => 'Q2', 10 => 'Q3', 1 => 'Q4'];
        $out = [];
        $q = \Illuminate\Support\Carbon::now()->startOfQuarter();
        for ($i = 0; $i < 6; $i++) {
            $qs = $q->copy()->subMonths(3 * $i);
            $sm = (int) $qs->month; $y = (int) $qs->year;
            $label = $labels[$sm] ?? 'Q';
            $fy = $sm >= 4 ? $y.'-'.substr((string) ($y + 1), -2) : ($y - 1).'-'.substr((string) $y, -2);
            $due = match ($sm) {
                4 => \Illuminate\Support\Carbon::create($y, 7, 31),
                7 => \Illuminate\Support\Carbon::create($y, 10, 31),
                10 => \Illuminate\Support\Carbon::create($y + 1, 1, 31),
                default => \Illuminate\Support\Carbon::create($y, 5, 31),
            };
            $status = $i === 0 ? 'Pending' : 'Filed';
            $out[] = [
                'id' => '24Q-'.$fy.'-'.$label,
                'quarter' => $fy.' '.$label,
                'deductees' => $deductees,
                'taxDeducted' => $perQ,
                'deposited' => $status === 'Filed' ? $perQ : 0,
                'dueDate' => $due->format('d M Y'),
                'status' => $status,
            ];
        }

        return $out;
    }

    /** Indian monthly payroll breakdown from CTC, using configurable statutory rates. */
    /**
     * Estimated monthly salary TDS (Sec 192) under the New Regime, FY 2025-26:
     * ₹75,000 standard deduction, 87A rebate makes tax NIL up to ₹12,00,000
     * taxable, slabs 4/8/12/16/20/24L, 4% cess, with marginal relief just above
     * ₹12L. Input is the (projected) ANNUAL CTC; returns the MONTHLY TDS.
     * It is a good-faith estimate — actual TDS depends on the employee's
     * declarations/exemptions, which a payroll admin can still override.
     */
    public static function salaryTdsMonthly(float $annualCtc, ?array $rates = null): float
    {
        // rev165: drive standard deduction / 87A rebate / slabs / cess from the
        // admin-editable Statutory Settings instead of a hardcoded table that
        // ignored config and disagreed with SettingsController::defaults(). Shares
        // the slab + cess maths with newRegimeTax() so the payslip TDS and the 24Q
        // return always use the same numbers.
        $r = $rates ?: SettingsController::defaults();
        $std = (float) ($r['std_deduction'] ?? 0);
        $rebate = (float) ($r['rebate_87a_limit'] ?? 0);
        $taxable = max(0.0, $annualCtc - $std);
        $tax = self::newRegimeTax($taxable, $r); // 0 up to the rebate ceiling; slabs + cess above it
        if ($taxable > $rebate) {
            // Marginal relief: total tax (incl. cess) can't exceed the income
            // earned above the rebate ceiling.
            $tax = min($tax, ($taxable - $rebate) * (1 + (float) ($r['cess_rate'] ?? 0) / 100));
        }

        return round(max(0.0, $tax) / 12, 2);
    }

    /**
     * Professional Tax — statutory MONTHLY slab on gross (not a flat amount).
     * Default = Telangana: up to ₹15,000 → Nil; ₹15,001–20,000 → ₹150; above ₹20,000 → ₹200.
     * A company can override with $rates['pt_slabs'] = [['upto'=>15000,'amt'=>0], ...].
     */
    public static function ptForGross(float $gross, array $r): float
    {
        $slabs = $r['pt_slabs'] ?? null;
        if (! is_array($slabs) || ! $slabs) {
            $slabs = [
                ['upto' => 15000.0, 'amt' => 0.0],
                ['upto' => 20000.0, 'amt' => 150.0],
                ['upto' => PHP_FLOAT_MAX, 'amt' => 200.0],
            ];
        }
        foreach ($slabs as $s) {
            if ($gross <= (float) ($s['upto'] ?? PHP_FLOAT_MAX)) {
                return round((float) ($s['amt'] ?? 0), 2);
            }
        }
        return 0.0;
    }

    /**
     * Statutory PF/ESI/PT per government rules. Returns employee deductions
     * (pf, esi, pt) PLUS employer contributions for the ECR/challan (which are
     * NOT payslip deductions, so callers must not add them to total_ded).
     *  - PF: 12% of PF wage (Basic + DA), capped at the ₹15,000 wage ceiling.
     *        Employer 12% = EPS 8.33% (on wage capped ₹15,000, max ₹1,250) + EPF (3.67% balance);
     *        EDLI 0.5% (max ₹75) shown separately.
     *  - ESI: on GROSS when gross ≤ ₹21,000; employee 0.75% + employer 3.25%,
     *        each rounded UP to the next rupee (ESIC rule).
     *  - PT: monthly slab via ptForGross().
     */
    public static function statutory(float $gross, float $pfWage, array $r): array
    {
        $pfCap = (float) ($r['pf_wage_cap'] ?? 15000);
        $pfRate = (float) ($r['pf_rate'] ?? 12);
        $pfBase = min(max($pfWage, 0.0), $pfCap);
        $epsBase = min($pfBase, 15000.0); // EPS/EDLI statutory wage ceiling is ₹15,000

        $pfEmployee = round($pfBase * $pfRate / 100, 2);
        $pfEmployer = round($pfBase * $pfRate / 100, 2);
        $eps = min(round($epsBase * 8.33 / 100, 2), 1250.0);
        $epfEmployer = round($pfEmployer - $eps, 2);
        $edli = round($epsBase * 0.5 / 100, 2);

        $esiThr = (float) ($r['esi_threshold'] ?? 21000);
        $esiEligible = $gross > 0 && $gross <= $esiThr;
        $esiEmployee = $esiEligible ? (float) ceil($gross * ((float) ($r['esi_employee_rate'] ?? 0.75)) / 100) : 0.0;
        $esiEmployer = $esiEligible ? (float) ceil($gross * ((float) ($r['esi_employer_rate'] ?? 3.25)) / 100) : 0.0;

        // Optional Conveyance deduction — SAME FORMULA AS PF: rate% of the PF wage
        // base (min(Basic + DA, cap)). Off unless enabled + rate > 0 in Statutory Settings.
        $convRate = (float) ($r['conveyance_rate'] ?? 0);
        $conveyance = (! empty($r['conveyance_enabled']) && $convRate > 0) ? round($pfBase * $convRate / 100, 2) : 0.0;

        return [
            'pf' => $pfEmployee, 'esi' => $esiEmployee, 'pt' => self::ptForGross($gross, $r),
            'conveyance' => $conveyance,
            'pf_wage' => round($pfBase, 2),
            'pf_employer' => $pfEmployer, 'pf_eps' => $eps, 'pf_epf_employer' => $epfEmployer, 'pf_edli' => $edli,
            'esi_employer' => $esiEmployer,
        ];
    }

    public static function computeSlip(float $ctc, ?array $rates = null): array
    {
        $r = $rates ?: SettingsController::defaults();
        $gross = round($ctc / 12, 2);
        $basic = round($gross * 0.5, 2);
        $hra = round($basic * 0.4, 2);
        $special = round($gross - $basic - $hra, 2);
        // No salary components defined → PF wage falls back to the assumed Basic (50% of gross).
        $st = self::statutory($gross, $basic, $r);
        $pf = $st['pf'];
        $esi = $st['esi'];
        $pt = $st['pt'];
        $tds = self::salaryTdsMonthly($ctc, $r);
        // Optional Labour Welfare Fund (state-specific) — OFF unless enabled in Settings.
        $lwf = (! empty($r['lwf_enabled'])) ? (float) ($r['lwf_employee'] ?? 0) : 0.0;
        // Optional Conveyance deduction — computed like PF (rate% of capped Basic).
        $conveyance = (float) ($st['conveyance'] ?? 0);
        $totalDed = round($pf + $esi + $pt + $tds + $lwf + $conveyance, 2);

        return [
            'gross' => $gross, 'basic' => $basic, 'hra' => $hra, 'special' => $special, 'conveyance' => $conveyance,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds, 'lwf' => round($lwf, 2),
            'pf_wage' => $st['pf_wage'], 'pf_employer' => $st['pf_employer'], 'pf_eps' => $st['pf_eps'],
            'pf_epf_employer' => $st['pf_epf_employer'], 'pf_edli' => $st['pf_edli'], 'esi_employer' => $st['esi_employer'],
            'total_ded' => $totalDed, 'net' => round(max(0, $gross - $totalDed), 2), // rev172 (M6) — never a negative payslip
        ];
    }

    /**
     * Compute a payslip from a company's defined salary components instead of the
     * fixed Basic/HRA split. Monthly gross stays CTC/12; earning components split
     * it (a "balance" component absorbs the remainder), PF is computed on the
     * component Basic, and custom deduction components are subtracted on top of
     * statutory PF/ESI/PT. Returns the same keys as computeSlip() PLUS detailed
     * 'earnings' and 'deductions' maps. Returns null if there are no components
     * (caller falls back to computeSlip).
     *
     * Each component: ctype (earning|deduction), base (fixed | pct_gross |
     * pct_basic | balance), calc_value (amount or percent), seq (order).
     */
    public static function computeSlipFromComponents(float $ctc, $components, ?array $rates = null): ?array
    {
        $comps = [];
        foreach ($components as $c) {
            $comps[] = (array) $c;
        }
        if (! $comps) {
            return null;
        }
        $r = $rates ?: SettingsController::defaults();
        $gross = round($ctc / 12, 2);

        usort($comps, fn ($a, $b) => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));

        $baseOf = function (array $c) {
            $b = strtolower(trim((string) ($c['base'] ?? '')));
            if ($b !== '') {
                return $b;
            }
            // Back-compat: infer from the old calc_type column.
            return strtolower(trim((string) ($c['calc_type'] ?? 'fixed'))) === 'percent' ? 'pct_gross' : 'fixed';
        };
        $val = fn (array $c) => (float) ($c['calc_value'] ?? 0);
        $isBasic = fn (array $c) => str_contains(strtolower((string) ($c['code'] ?? '').' '.(string) ($c['name'] ?? '')), 'basic');
        $nameOf = fn (array $c) => (string) (($c['name'] ?? '') ?: ($c['code'] ?? 'Component'));

        // A component is a REIMBURSEMENT when its category says so, or its name/code
        // clearly is one. Reimbursements are paid on top of the wage: they are carved
        // out of the "balance" and EXCLUDED from the statutory (PF/ESI/PT) wage base,
        // matching how bills-based reimbursements are treated. With none present the
        // maths is byte-identical to before (wage gross = gross).
        $isReimb = function (array $c) {
            $cat = strtolower(trim((string) ($c['category'] ?? '')));
            if ($cat === 'reimbursement') {
                return true;
            }
            $txt = strtolower((string) ($c['code'] ?? '').' '.(string) ($c['name'] ?? ''));
            return str_contains($txt, 'reimburs');
        };
        $allEarn = array_values(array_filter($comps, fn ($c) => ($c['ctype'] ?? 'earning') !== 'deduction'));
        $reimbComps = array_values(array_filter($allEarn, $isReimb));
        $earnComps = array_values(array_filter($allEarn, fn ($c) => ! $isReimb($c)));
        $dedComps = array_values(array_filter($comps, fn ($c) => ($c['ctype'] ?? '') === 'deduction'));

        // Resolve Basic first (so pct_basic components can reference it).
        $basic = 0.0;
        foreach ($earnComps as $c) {
            if ($isBasic($c)) {
                $base = $baseOf($c);
                $basic += $base === 'fixed' ? $val($c) : ($val($c) / 100 * $gross);
            }
        }

        // Reimbursement amounts (usually fixed; pct supported). Summed OUT of the
        // wage gross used for the balance split and statutory deductions.
        $reimbursements = [];
        $reimbTotal = 0.0;
        foreach ($reimbComps as $c) {
            $base = $baseOf($c);
            if ($base === 'pct_basic') {
                $amt = $val($c) / 100 * $basic;
            } elseif ($base === 'fixed') {
                $amt = $val($c);
            } else { // pct_gross / anything else
                $amt = $val($c) / 100 * $gross;
            }
            $amt = round($amt, 2);
            $reimbursements[$nameOf($c)] = ($reimbursements[$nameOf($c)] ?? 0) + $amt;
            $reimbTotal += $amt;
        }
        $wageGross = round($gross - $reimbTotal, 2);

        $earnings = [];
        $earnSum = 0.0;
        foreach ($earnComps as $c) {
            if ($baseOf($c) === 'balance') {
                continue; // handled after, absorbs the remainder
            }
            $base = $baseOf($c);
            if ($isBasic($c)) {
                $amt = $base === 'fixed' ? $val($c) : ($val($c) / 100 * $gross);
            } elseif ($base === 'pct_basic') {
                $amt = $val($c) / 100 * $basic;
            } elseif ($base === 'fixed') {
                $amt = $val($c);
            } else { // pct_gross / pct_ctc / anything else
                $amt = $val($c) / 100 * $gross;
            }
            $amt = round($amt, 2);
            $earnings[$nameOf($c)] = ($earnings[$nameOf($c)] ?? 0) + $amt;
            $earnSum += $amt;
        }

        // Balance components share the remainder so earnings reconcile to the WAGE
        // gross (gross minus reimbursements). With no reimbursements this is gross.
        $balanceComps = array_values(array_filter($earnComps, fn ($c) => $baseOf($c) === 'balance'));
        $remainder = round($wageGross - $earnSum, 2);
        if ($balanceComps) {
            $n = count($balanceComps);
            $each = round($remainder / $n, 2);
            foreach ($balanceComps as $i => $c) {
                $amt = $i === $n - 1 ? round($remainder - $each * ($n - 1), 2) : $each;
                $earnings[$nameOf($c)] = ($earnings[$nameOf($c)] ?? 0) + $amt;
                $earnSum += $amt;
            }
        } elseif (abs($remainder) >= 1) {
            // No balance component → reconcile via Special Allowance.
            $earnings['Special Allowance'] = round(($earnings['Special Allowance'] ?? 0) + $remainder, 2);
            $earnSum += $remainder;
        }
        if ($basic <= 0) {
            $basic = $earnSum > 0 ? (float) reset($earnings) : 0.0; // PF fallback: first earning
        }

        // Statutory deductions per govt rules: PF on Basic + DA (capped ₹15k),
        // ESI on gross (round-up), PT by slab.
        $da = 0.0;
        foreach ($earnings as $en => $ea) {
            $lc = strtolower((string) $en);
            if (str_contains($lc, 'dearness') || preg_match('/(^|[^a-z])da([^a-z]|$)/', $lc)) {
                $da += (float) $ea;
            }
        }
        $st = self::statutory($wageGross, $basic + $da, $r);
        $pf = $st['pf'];
        $esi = $st['esi'];
        $pt = $st['pt'];
        $tds = self::salaryTdsMonthly($ctc, $r);
        $deductions = ['PF' => $pf, 'ESI' => $esi, 'Professional Tax' => $pt, 'TDS' => $tds];
        foreach ($dedComps as $c) {
            $base = $baseOf($c);
            if ($base === 'pct_basic') {
                $amt = $val($c) / 100 * $basic;
            } elseif ($base === 'fixed') {
                $amt = $val($c);
            } else {
                $amt = $val($c) / 100 * $gross;
            }
            $deductions[$nameOf($c)] = round(($deductions[$nameOf($c)] ?? 0) + round($amt, 2), 2);
        }
        // Optional Labour Welfare Fund (state-specific) — OFF unless enabled in Settings.
        $lwf = (! empty($r['lwf_enabled'])) ? (float) ($r['lwf_employee'] ?? 0) : 0.0;
        if ($lwf > 0) {
            $deductions['Labour Welfare Fund'] = round(($deductions['Labour Welfare Fund'] ?? 0) + $lwf, 2);
        }
        // Optional Conveyance deduction — SAME FORMULA AS PF (rate% of capped Basic+DA).
        $conveyance = (float) ($st['conveyance'] ?? 0);
        if ($conveyance > 0) {
            $deductions['Conveyance'] = round(($deductions['Conveyance'] ?? 0) + $conveyance, 2);
        }
        $totalDed = round(array_sum($deductions), 2);

        // Legacy keys for the fixed-column preview (computed on wage earnings only,
        // before reimbursements are folded into the display map).
        $hra = 0.0;
        foreach ($earnings as $name => $amt) {
            if (stripos($name, 'hra') !== false || stripos($name, 'rent') !== false) {
                $hra += $amt;
            }
        }
        $special = round($earnSum - $basic - $hra, 2);

        // Fold reimbursements into the earnings map so they appear on the slip.
        // gross stays CTC/12 = wage earnings + reimbursements, so net = gross - deductions.
        foreach ($reimbursements as $rn => $ra) {
            $earnings[$rn] = round(($earnings[$rn] ?? 0) + $ra, 2);
        }

        return [
            'gross' => $gross, 'basic' => round($basic, 2), 'hra' => round($hra, 2), 'special' => $special,
            'earnings' => $earnings, 'deductions' => $deductions,
            'reimbursements' => round($reimbTotal, 2), 'reimbursement_map' => $reimbursements, 'wage_gross' => $wageGross,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds, 'lwf' => round($lwf, 2),
            'pf_wage' => $st['pf_wage'], 'pf_employer' => $st['pf_employer'], 'pf_eps' => $st['pf_eps'],
            'pf_epf_employer' => $st['pf_epf_employer'], 'pf_edli' => $st['pf_edli'], 'esi_employer' => $st['esi_employer'],
            'total_ded' => $totalDed, 'net' => round(max(0, $gross - $totalDed), 2), // rev172 (M6) — never a negative payslip
        ];
    }

    /** Generate the last 12 months of payroll (idempotent) and return runs + payslips history. */
    private function ensurePayrollHistory(?int $tenantId, $companyId, $emps, ?array $rates = null): array
    {
        if (! $tenantId || ! $companyId || $emps->isEmpty()) {
            return ['runs' => collect(), 'payslips' => collect()];
        }

        for ($k = 0; $k < 12; $k++) {
            $month = now()->subMonths($k)->format('Y-m');
            $exists = DB::table('payroll_runs')->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)->where('cycle_label', $month)->exists();
            if ($exists) {
                continue;
            }
            $payDate = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString();
            // Only write columns that exist in THIS deployment's schema — the
            // deployed payroll_runs table has no generated_at, and payslips has no
            // uuid; including them throws "Unknown column" and 500s /app/data.
            $runRow = $this->onlyExistingCols('payroll_runs', [
                'tenant_id' => $tenantId, 'company_id' => $companyId, 'lot' => 1,
                'cycle_label' => $month, 'pay_date' => $payDate,
                'status' => $k === 0 ? 'draft' : 'paid', 'employees_count' => $emps->count(), 'net_total' => 0,
                'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $runId = DB::table('payroll_runs')->insertGetId($runRow);
            $netTotal = 0;
            foreach ($emps as $e) {
                $s = self::computeSlip((float) $e->ctc, $rates);
                $slipRow = $this->onlyExistingCols('payslips', [
                    'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'company_id' => $companyId,
                    'employee_id' => $e->id, 'run_id' => $runId, 'month' => $month,
                    'earnings' => json_encode(['Basic' => $s['basic'], 'HRA' => $s['hra'], 'Special Allowance' => $s['special']]),
                    'deductions' => json_encode(['PF' => $s['pf'], 'ESI' => $s['esi'], 'Professional Tax' => $s['pt'], 'TDS' => $s['tds']]),
                    'gross' => $s['gross'], 'total_ded' => $s['total_ded'], 'net' => $s['net'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('payslips')->insert($slipRow);
                $netTotal += $s['net'];
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['net_total' => $netTotal]);
        }

        $runs = DB::table('payroll_runs')->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->orderBy('cycle_label', 'desc')->get()->map(fn ($r) => [
                'id' => 'RUN-'.$r->id,
                'lot' => 'Lot 1',
                'payDate' => \Illuminate\Support\Carbon::parse($r->pay_date)->format('d M Y'),
                'cycle' => \Illuminate\Support\Carbon::parse($r->cycle_label.'-01')->format('M Y'),
                'components' => 'All',
                'employees' => (int) $r->employees_count,
                'net' => (float) $r->net_total,
                'slip' => 'Same day',
                'status' => ucfirst($r->status),
            ])->values();

        // Per-line lifecycle column may not exist on the deployed schema until the
        // salary-approval drill-down has created it — select it only if present.
        $hasLine = \Illuminate\Support\Facades\Schema::hasColumn('payslips', 'line_status');
        $lineLabels = [
            'pending' => 'Prepared', 'on_hold' => 'On hold', 'in_review' => 'In review',
            'approved' => 'Approved', 'disbursed' => 'Disbursed — acknowledge', 'acknowledged' => 'Acknowledged (signed)',
            'rejected' => 'Rejected',
        ];
        $psCols = ['p.id', 'p.month', 'p.gross', 'p.total_ded', 'p.net', 'e.emp_code', 'e.name', 'e.email',
            'c.name as company', 'r.status as run_status'];
        if ($hasLine) {
            $psCols[] = 'p.line_status';
        }
        $payslips = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
            ->leftJoin('payroll_runs as r', 'r.id', '=', 'p.run_id')
            ->where('p.tenant_id', $tenantId)
            ->orderBy('p.month', 'desc')->orderBy('e.emp_code')
            ->get($psCols)
            ->map(function ($p) use ($hasLine, $lineLabels) {
                $ls = $hasLine ? ($p->line_status ?: 'pending') : 'pending';

                return [
                    'id' => (int) $p->id,
                    'code' => $p->emp_code, 'name' => $p->name, 'email' => $p->email ?? '', 'month' => $p->month,
                    'monthLabel' => \Illuminate\Support\Carbon::parse($p->month.'-01')->format('M Y'),
                    'gross' => (float) $p->gross, 'ded' => (float) $p->total_ded, 'net' => (float) $p->net,
                    'company' => $p->company ?? '',
                    'status' => ucfirst($p->run_status ?? 'draft'),
                    'lineStatus' => $ls,
                    'lineLabel' => $lineLabels[$ls] ?? ucfirst($ls),
                ];
            })->values();

        return ['runs' => $runs, 'payslips' => $payslips];
    }

    /** Persist a new employee (from the prototype Add Employee form) into the real tables. */
    public function storeEmployee(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $user = $request->user();
        $tenantId = $user->tenant_id ?? DB::table('tenants')->value('id');
        $companyId = DB::table('companies')
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->value('id');

        try {
            self::ensureEmployeeColumns();
        } catch (\Throwable $e) {
            // optional columns; continue
        }

        $e = (array) $request->input('employee', []);
        // rev172 (H3) — neutralise stored XSS at the source: employee names and
        // free-text fields are rendered into the SPA via innerHTML in many places
        // with inconsistent escaping, so strip any HTML/angle-brackets on save.
        // Legitimate names never contain < or >. Also cleans the references rows.
        $e = self::stripHtmlDeep($e);
        if (empty($e['name'])) {
            return response()->json(['ok' => false, 'error' => 'Name required'], 422);
        }

        $salaryMap = [
            'Salary' => 'only_salary',
            'Salary + Commission' => 'salary_commission',
            'Commission' => 'only_commission',
        ];
        $type = stripos($e['type'] ?? '', 'field') !== false ? 'field' : 'office';
        $code = $e['id'] ?? ('EMP-'.random_int(1000, 9999));

        $payload = [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'emp_code' => $code,
            'name' => $e['name'],
            'type' => $type,
            'ctc' => (float) ($e['ctc'] ?? 0),
            'salary_type' => $salaryMap[$e['salaryType'] ?? 'Salary'] ?? 'only_salary',
            'mobile' => $e['mobile'] ?? null,
            'whatsapp' => $e['whatsapp'] ?? null,
            'email' => $e['email'] ?? null,
            'pan' => $e['pan'] ?? null,
            'uan' => $e['uan'] ?? null,
            'pt_state' => $e['ptState'] ?? null,
            'bank_name' => $e['bankName'] ?? null,
            'bank_acc' => $e['bankAcc'] ?? null,
            'ifsc' => $e['ifsc'] ?? null,
            'doj' => ! empty($e['doj']) ? $e['doj'] : null,
            // Org hierarchy (names from the form dropdowns). The prototype form
            // uses keys dept/designation/branch/team/teamManager/teamLeader; we
            // also accept reporting/leader as fallbacks for API callers.
            'department' => $e['dept'] ?? null,
            'designation' => $e['designation'] ?? null,
            'branch' => $e['branch'] ?? null,
            'team' => $e['team'] ?? null,
            'reporting_manager' => $e['teamManager'] ?? ($e['reporting'] ?? null),
            'team_leader' => $e['teamLeader'] ?? ($e['leader'] ?? null),
            // rev160: Personal Details — Father / Spouse / Blood group / ID marks (+ gender, address, dob).
            'father' => $e['father'] ?? null,
            'spouse' => $e['spouse'] ?? null,
            'blood_group' => $e['bloodGroup'] ?? null,
            'id_marks' => $e['idMarks'] ?? null,
            'gender' => $e['gender'] ?? null,
            'address' => $e['addr'] ?? ($e['address'] ?? null),
            'dob' => $e['dob'] ?? null,
            'updated_at' => now(),
        ];

        // Upsert by (tenant_id, emp_code): edit updates the same record, add inserts.
        $existing = DB::table('employees')->where('tenant_id', $tenantId)->where('emp_code', $code)->first();
        if ($existing) {
            DB::table('employees')->where('id', $existing->id)->update($payload);
            $empId = $existing->id;
            DB::table('employee_references')->where('employee_id', $empId)->delete();
        } else {
            // SEAT LIMIT (rev 75): a NEW employee must fit within the subscribed
            // seats (active on-roll count). Edits of existing employees are never
            // blocked. Tenants without a seat limit on record are unrestricted.
            $seat = \App\Services\SubscriptionService::canAddEmployees($user->tenant_id ? (int) $user->tenant_id : null, 1);
            if (! $seat['ok']) {
                return response()->json(['ok' => false, 'error' => $seat['error']], 422);
            }
            $payload['uuid'] = (string) Str::uuid();
            $payload['status'] = 'active';
            $payload['created_at'] = now();
            $empId = DB::table('employees')->insertGetId($payload);
        }

        foreach ((array) ($e['refs'] ?? []) as $r) {
            if (empty($r['name'])) {
                continue;
            }
            $v = (array) ($r['verify'] ?? []);
            DB::table('employee_references')->insert([
                'employee_id' => $empId,
                'name' => $r['name'],
                'relation' => $r['relation'] ?? null,
                'aadhaar' => $r['aadhaar'] ?? null,
                'pan' => $r['pan'] ?? null,
                'mobile' => $r['mobile'] ?? null,
                'verify_email' => ! empty($v['email']),
                'verify_sms' => ! empty($v['sms']),
                'verify_call' => ! empty($v['call']),
                'verify_whatsapp' => ! empty($v['whatsapp']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true, 'id' => $empId, 'emp_code' => $code]);
    }
}
