<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Self-Service (ESS): one endpoint that returns the logged-in
 * employee's own snapshot — profile, recent payslips (with PDF links), this
 * month's attendance, recent leave, and active notices. Rendered on the
 * Account screen so every employee has a personal "My Space".
 */
class EssController extends Controller
{
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            $tid = $user->tenant_id;
            $emp = $this->currentEmployee($request);
            if (! $emp) {
                return response()->json(['ok' => true, 'linked' => false]);
            }

            return response()->json([
                'ok' => true,
                'linked' => true,
                'profile' => $this->profile($emp),
                'payslips' => $this->payslips($emp),
                'attendance' => $this->attendance($emp, $tid),
                'leaves' => $this->leaves($emp, $tid),
                'notices' => $this->notices($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
        if (! empty($user->employee_id)) {
            $e = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($e) {
                return $e;
            }
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
            ->first();
    }

    private function nameFrom(string $table, $id): string
    {
        if (! $id || ! Schema::hasTable($table)) {
            return '';
        }

        return (string) (DB::table($table)->where('id', $id)->value('name') ?: '');
    }

    private function profile($e): array
    {
        $a = (array) $e;
        $designation = $a['designation'] ?? '';
        if (! $designation && ! empty($a['designation_id'])) {
            $designation = $this->nameFrom('designations', $a['designation_id']);
        }
        $department = $a['department'] ?? '';
        if (! $department && ! empty($a['department_id'])) {
            $department = $this->nameFrom('departments', $a['department_id']);
        }

        return [
            'name' => $a['name'] ?? '',
            'emp_code' => $a['emp_code'] ?? '',
            'designation' => $designation,
            'department' => $department,
            'type' => $a['type'] ?? '',
            'email' => $a['email'] ?? '',
            'mobile' => $a['mobile'] ?? '',
            'joined' => $a['doj'] ?? ($a['joined_on'] ?? null),
            'photo' => ! empty($a['photo_path']) ? url('/app/emp-photo/'.($a['emp_code'] ?? '')) : '',
        ];
    }

    private function payslips($e): array
    {
        if (! Schema::hasTable('payslips')) {
            return [];
        }
        $code = $e->emp_code ?? '';

        return DB::table('payslips')->where('employee_id', $e->id)
            ->orderByDesc('month')->orderByDesc('id')->limit(6)
            ->get(['month', 'gross', 'net'])
            ->map(fn ($p) => [
                'month' => $p->month,
                'label' => $p->month ? Carbon::parse($p->month.'-01')->format('M Y') : '',
                'gross' => (float) $p->gross,
                'net' => (float) $p->net,
                'pdf' => $code ? url('/app/payslip/'.$code.'/pdf?month='.$p->month) : null,
            ])->all();
    }

    private function attendance($e, $tid): array
    {
        $out = ['present' => 0, 'last_punch' => null, 'month' => now()->format('M Y')];
        if (! Schema::hasTable('attendance_logs')) {
            return $out;
        }
        $month = now()->format('Y-m');
        $end = now()->endOfMonth()->toDateString();
        $code = $e->emp_code ?? '';
        $out['present'] = (int) DB::table('attendance_logs')->where('emp_code', $code)
            ->whereBetween('log_date', [$month.'-01', $end])->distinct()->count('log_date');
        $last = DB::table('attendance_logs')->where('emp_code', $code)->orderByDesc('punch_at')->value('punch_at');
        $out['last_punch'] = $last ? Carbon::parse($last)->format('d M, H:i') : null;

        return $out;
    }

    private function leaves($e, $tid): array
    {
        if (! Schema::hasTable('leaves')) {
            return [];
        }
        $types = Schema::hasTable('leave_types') ? DB::table('leave_types')->pluck('name', 'id')->all() : [];

        return DB::table('leaves')->where('employee_id', $e->id)
            ->orderByDesc('id')->limit(6)->get()
            ->map(function ($l) use ($types) {
                $a = (array) $l;

                return [
                    'type' => $types[$a['type_id'] ?? null] ?? ($a['type'] ?? 'Leave'),
                    'from' => isset($a['from_date']) ? substr((string) $a['from_date'], 0, 10) : '',
                    'to' => isset($a['to_date']) ? substr((string) $a['to_date'], 0, 10) : '',
                    'status' => $a['status'] ?? 'pending',
                ];
            })->all();
    }

    private function notices($tid): array
    {
        if (! Schema::hasTable('notices')) {
            return [];
        }
        try {
            return DB::table('notices')
                ->when($tid && Schema::hasColumn('notices', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('notices', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereRaw("LOWER(status) NOT IN ('inactive', 'archived', 'draft', 'expired')");
                })
                ->orderByDesc('posted_on')->orderByDesc('id')->limit(5)
                ->get(['title', 'body', 'posted_on'])
                ->map(fn ($n) => ['title' => $n->title, 'body' => $n->body, 'date' => $n->posted_on])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
