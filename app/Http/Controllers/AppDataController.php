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
        $cols = ['department', 'designation', 'branch', 'team', 'reporting_manager', 'team_leader'];
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
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

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
        self::ensureEmployeeColumns();
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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('payslip-pdf', [
            'e' => $e,
            'company' => $company,
            'brand' => ConfigController::brandFor($e->tenant_id, $e->company_id),
            'month' => $month,
            'monthLabel' => \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y'),
            's' => $s,
            'note' => $note,
            'earnMap' => $earnMap,
            'dedMap' => $dedMap,
        ]);

        return $pdf->download('payslip-'.$code.'-'.$month.'.pdf');
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
    public static function salaryTdsMonthly(float $annualCtc): float
    {
        $std = 75000.0;
        $taxable = max(0.0, $annualCtc - $std);
        if ($taxable <= 1200000.0) {
            return 0.0; // 87A rebate (new regime)
        }
        $slabs = [[400000.0, 0.0], [800000.0, 0.05], [1200000.0, 0.10], [1600000.0, 0.15], [2000000.0, 0.20], [2400000.0, 0.25], [PHP_FLOAT_MAX, 0.30]];
        $tax = 0.0;
        $prev = 0.0;
        foreach ($slabs as [$upto, $rate]) {
            if ($taxable <= $prev) {
                break;
            }
            $tax += (min($taxable, $upto) - $prev) * $rate;
            $prev = $upto;
        }
        // Marginal relief: tax can't exceed the income earned above the ₹12L rebate ceiling.
        $tax = min($tax, $taxable - 1200000.0);
        $tax = max(0.0, $tax) * 1.04; // 4% health & education cess

        return round($tax / 12, 2);
    }

    public static function computeSlip(float $ctc, ?array $rates = null): array
    {
        $r = $rates ?: SettingsController::defaults();
        $gross = round($ctc / 12, 2);
        $basic = round($gross * 0.5, 2);
        $hra = round($basic * 0.4, 2);
        $special = round($gross - $basic - $hra, 2);
        $pf = round(min($basic, (float) $r['pf_wage_cap']) * ((float) $r['pf_rate'] / 100), 2);
        $esi = $gross <= (float) $r['esi_threshold'] ? round($gross * ((float) $r['esi_employee_rate'] / 100), 2) : 0.0;
        $pt = $gross > 0 ? (float) $r['pt_amount'] : 0.0;
        $tds = self::salaryTdsMonthly($ctc);
        $totalDed = round($pf + $esi + $pt + $tds, 2);

        return [
            'gross' => $gross, 'basic' => $basic, 'hra' => $hra, 'special' => $special,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds,
            'total_ded' => $totalDed, 'net' => round($gross - $totalDed, 2),
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

        $earnComps = array_values(array_filter($comps, fn ($c) => ($c['ctype'] ?? 'earning') !== 'deduction'));
        $dedComps = array_values(array_filter($comps, fn ($c) => ($c['ctype'] ?? '') === 'deduction'));

        // Resolve Basic first (so pct_basic components can reference it).
        $basic = 0.0;
        foreach ($earnComps as $c) {
            if ($isBasic($c)) {
                $base = $baseOf($c);
                $basic += $base === 'fixed' ? $val($c) : ($val($c) / 100 * $gross);
            }
        }

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

        // Balance components share the remainder so earnings reconcile to gross.
        $balanceComps = array_values(array_filter($earnComps, fn ($c) => $baseOf($c) === 'balance'));
        $remainder = round($gross - $earnSum, 2);
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

        // Statutory deductions (PF on the component Basic).
        $pf = round(min($basic, (float) $r['pf_wage_cap']) * ((float) $r['pf_rate'] / 100), 2);
        $esi = $gross <= (float) $r['esi_threshold'] ? round($gross * ((float) $r['esi_employee_rate'] / 100), 2) : 0.0;
        $pt = $gross > 0 ? (float) $r['pt_amount'] : 0.0;
        $tds = self::salaryTdsMonthly($ctc);
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
        $totalDed = round(array_sum($deductions), 2);

        // Legacy keys for the fixed-column preview.
        $hra = 0.0;
        foreach ($earnings as $name => $amt) {
            if (stripos($name, 'hra') !== false || stripos($name, 'rent') !== false) {
                $hra += $amt;
            }
        }
        $special = round($earnSum - $basic - $hra, 2);

        return [
            'gross' => $gross, 'basic' => round($basic, 2), 'hra' => round($hra, 2), 'special' => $special,
            'earnings' => $earnings, 'deductions' => $deductions,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds,
            'total_ded' => $totalDed, 'net' => round($gross - $totalDed, 2),
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
