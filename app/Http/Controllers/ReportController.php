<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports (rev 39) — a simple, real export builder over existing data. Replaces
 * the prototype Reports screen. The user picks a dataset (+ optional month and
 * company), previews the first rows + a total count, and downloads a CSV.
 *
 * Read-only, tenant-scoped, admin/HR guarded, fail-soft JSON. Each dataset is a
 * [columns, rows] builder reused by BOTH preview() and export() so the CSV
 * always matches what was previewed.
 */
class ReportController extends Controller
{
    /** Datasets the report builder can produce. */
    public static function datasets(): array
    {
        return [
            'employees' => ['label' => 'Employees', 'month' => false],
            'payslips' => ['label' => 'Payslips', 'month' => true],
            'leaves' => ['label' => 'Leave records', 'month' => true],
            'attendance' => ['label' => 'Attendance punches', 'month' => true],
        ];
    }

    private function normMonth(?string $month): ?string
    {
        if (! $month) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m', substr($month, 0, 7))->format('Y-m');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build [columns[], rows[]] for a dataset. $limit caps rows (null = all).
     * Returns ['columns'=>[], 'rows'=>[], 'count'=>int].
     */
    private function build(?int $tid, string $dataset, ?string $month, ?int $companyId, ?int $limit): array
    {
        $end = $month ? Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString() : null;

        if ($dataset === 'employees') {
            $cols = ['Code', 'Name', 'Company', 'Type', 'CTC', 'Status', 'Mobile', 'Email'];
            $q = DB::table('employees as e')
                ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
                ->when($tid, fn ($x) => $x->where('e.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('e.company_id', $companyId))
                ->whereNull('e.deleted_at')
                ->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['e.emp_code', 'e.name', 'c.name as company', 'e.type', 'e.ctc', 'e.status', 'e.mobile', 'e.email'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Company' => $r->company, 'Type' => $r->type,
                    'CTC' => (float) $r->ctc, 'Status' => $r->status, 'Mobile' => $r->mobile, 'Email' => $r->email])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'payslips') {
            $cols = ['Month', 'Code', 'Name', 'Company', 'Gross', 'Deductions', 'Net'];
            $q = DB::table('payslips as p')
                ->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
                ->when($tid, fn ($x) => $x->where('p.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('p.company_id', $companyId))
                ->when($month, fn ($x) => $x->where('p.month', $month))
                ->orderByDesc('p.month')->orderBy('e.emp_code');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['p.month', 'e.emp_code', 'e.name', 'c.name as company', 'p.gross', 'p.total_ded', 'p.net'])
                ->map(fn ($r) => ['Month' => $r->month, 'Code' => $r->emp_code, 'Name' => $r->name, 'Company' => $r->company,
                    'Gross' => (float) $r->gross, 'Deductions' => (float) $r->total_ded, 'Net' => (float) $r->net])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'leaves') {
            $cols = ['Code', 'Name', 'Type', 'From', 'To', 'Days', 'Status'];
            $q = DB::table('leaves as l')
                ->join('employees as e', 'e.id', '=', 'l.employee_id')
                ->leftJoin('leave_types as t', 't.id', '=', 'l.type_id')
                ->when($tid, fn ($x) => $x->where('l.tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('l.company_id', $companyId))
                ->when($month, fn ($x) => $x->where('l.from_date', '<=', $end)->where('l.to_date', '>=', $month.'-01'))
                ->orderByDesc('l.from_date');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['e.emp_code', 'e.name', 't.name as type', 'l.from_date', 'l.to_date', 'l.days', 'l.status'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->name, 'Type' => $r->type ?: '—',
                    'From' => $r->from_date, 'To' => $r->to_date, 'Days' => (float) $r->days, 'Status' => $r->status])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        if ($dataset === 'attendance') {
            $cols = ['Code', 'Name', 'Date', 'Time', 'Direction'];
            if (! Schema::hasTable('attendance_logs')) {
                return ['columns' => $cols, 'rows' => [], 'count' => 0];
            }
            $q = DB::table('attendance_logs')
                ->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                ->when($companyId, fn ($x) => $x->where('company_id', $companyId))
                ->when($month, fn ($x) => $x->whereBetween('log_date', [$month.'-01', $end]))
                ->orderByDesc('log_date')->orderByDesc('punch_at');
            $count = (clone $q)->count();
            $rows = $q->when($limit, fn ($x) => $x->limit($limit))
                ->get(['emp_code', 'emp_name', 'log_date', 'punch_at', 'direction'])
                ->map(fn ($r) => ['Code' => $r->emp_code, 'Name' => $r->emp_name, 'Date' => $r->log_date,
                    'Time' => $r->punch_at ? Carbon::parse($r->punch_at)->format('H:i:s') : '', 'Direction' => $r->direction])->all();

            return ['columns' => $cols, 'rows' => $rows, 'count' => $count];
        }

        return ['columns' => [], 'rows' => [], 'count' => 0];
    }

    /** PREVIEW — dataset list + first rows + total count. */
    public function preview(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $defs = self::datasets();
            $dataset = (string) $request->query('dataset', 'employees');
            if (! isset($defs[$dataset])) {
                $dataset = 'employees';
            }
            $month = $defs[$dataset]['month'] ? $this->normMonth($request->query('month')) : null;
            $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

            $built = $this->build($tid, $dataset, $month, $companyId, 100);

            return response()->json([
                'ok' => true,
                'datasets' => collect($defs)->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label'], 'month' => $d['month']])->values(),
                'dataset' => $dataset,
                'label' => $defs[$dataset]['label'],
                'usesMonth' => $defs[$dataset]['month'],
                'month' => $month,
                'columns' => $built['columns'],
                'rows' => $built['rows'],
                'count' => $built['count'],
                'shown' => count($built['rows']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** EXPORT — stream the full dataset as a CSV download. */
    public function export(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $defs = self::datasets();
            $dataset = (string) $request->query('dataset', 'employees');
            if (! isset($defs[$dataset])) {
                $dataset = 'employees';
            }
            $month = $defs[$dataset]['month'] ? $this->normMonth($request->query('month')) : null;
            $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

            $built = $this->build($tid, $dataset, $month, $companyId, null);

            $out = $this->csvLine($built['columns']);
            foreach ($built['rows'] as $row) {
                $line = [];
                foreach ($built['columns'] as $c) {
                    $line[] = $row[$c] ?? '';
                }
                $out .= $this->csvLine($line);
            }

            $fname = 'smartprs-'.$dataset.($month ? '-'.$month : '').'-'.now()->format('Ymd').'.csv';

            return response($out, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$fname.'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** One RFC-4180-ish CSV line from a list of values. */
    private function csvLine(array $vals): string
    {
        $cells = array_map(function ($v) {
            $v = (string) $v;
            if (preg_match('/[",\r\n]/', $v)) {
                $v = '"'.str_replace('"', '""', $v).'"';
            }

            return $v;
        }, $vals);

        return implode(',', $cells)."\r\n";
    }
}
