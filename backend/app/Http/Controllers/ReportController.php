<?php

namespace App\Http\Controllers;

use App\Support\Percent;
use App\Services\ProjectFinancialService;
use App\Models\Project;
use App\Services\DashboardService;
use App\Services\FormBReportService;
use App\Services\PayrollRegisterService;
use App\Support\Csv;
use App\Support\ReportSignatories;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private DashboardService $dashboard, private PayrollRegisterService $register, private FormBReportService $formB)
    {
    }

    /**
     * GET /api/projects/{project}/payroll-register?period=all|year|90d&month=YYYY-MM
     * Verified payroll batches only, per month and per person (Reports PDF,
     * the Research Assistant's Payroll page). Project access is enforced by
     * EnsureProjectAccess: the Program Leader, or a user assigned to it.
     * Personnel pay: not for Researchers (User::canSeePersonnelPay).
     */
    public function payrollRegister(Request $request, Project $project)
    {
        $this->requirePayAccess($request, $project);

        return response()->json($this->registerFor($request, $project));
    }

    /**
     * GET /api/projects/{project}/form-b
     * The printable R&D Form B (official layout, the project's funder names);
     * the Reports page prints it as the PDF. The CSV is /reports/form-b.
     */
    public function formB(Project $project)
    {
        return response()->json($this->formB->build($project));
    }

    /**
     * GET /api/report-signatories
     * "Approved by" on the program-wide printed reports (Executive report,
     * Audit log): the Program Leader's name, or null (a blank line).
     */
    public function signatories()
    {
        return response()->json(['program_leader' => ReportSignatories::programLeader()]);
    }

    private function requirePayAccess(Request $request, Project $project): void
    {
        abort_unless($request->user()->canSeePersonnelPay($project), 403, 'The payroll register shows pay per person and is not available to Researchers.');
    }

    private function registerFor(Request $request, Project $project): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:' . implode(',', array_keys(PayrollRegisterService::PERIODS))],
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return $this->register->build($project, $data['period'] ?? 'all', $data['month'] ?? null);
    }

    /**
     * Export official monitoring reports as CSV. Supported types:
     * form-b (R&D Form B, FormBReportService), form-c (financial/fund-release), utilization, liquidation,
     * payroll-register (verified batches; ?period= / ?month= as above).
     */
    public function export(Request $request, Project $project, string $type)
    {
        $type = strtolower($type);
        $allowed = ['form-b', 'form-c', 'utilization', 'liquidation', 'payroll-register'];

        abort_unless(in_array($type, $allowed, true), 404, 'Unknown report type.');

        if ($type === 'payroll-register') {
            $this->requirePayAccess($request, $project);
            $register = $this->registerFor($request, $project);
            $filename = Str::slug($project->code . '-payroll-register-' . $register['period'] . '-' . now()->format('Ymd')) . '.csv';

            return response(Csv::render($this->register->csvRows($register)), 200, Csv::headers($filename));
        }

        if ($type === 'form-b') {
            $doc = $this->formB->build($project);
            $filename = Str::slug($project->code . '-form-b-' . now()->format('Ymd')) . '.csv';

            return response(Csv::render($this->formB->csvRows($doc)), 200, Csv::headers($filename));
        }

        $compliance = (string) $request->query('compliance', 'all');
        $summary = $this->dashboard->build($project);

        $rows = match ($type) {
            'form-c' => $this->formCRows($summary),
            'utilization' => $this->utilizationRows($summary),
            'liquidation' => $this->liquidationRows($project, $compliance),
        };

        $filename = Str::slug($project->code . '-' . $type . '-' . now()->format('Ymd')) . '.csv';

        return response(Csv::render($rows), 200, Csv::headers($filename));
    }

    protected function formCRows(array $summary): array
    {
        $project = $summary['project'];
        $rows = [
            ['R&D Form C — Financial and Fund-Release Report'],
            ['Project Code', $project['code']],
            ['Project Title', $project['title']],
            ['Project Leader', $project['project_leader']],
            ['Project Period', $project['period']],
            ['Generated', now()->toDateTimeString()],
            [],
            ['FUND RELEASES'],
            ['Release', 'Milestone', 'Amount', 'Status', 'Planned Release', 'Actual Release'],
        ];

        foreach ($summary['tranches']['items'] as $tranche) {
            $rows[] = [
                $tranche['name'],
                $tranche['milestone'] ?? '',
                $tranche['amount'],
                ucfirst($tranche['display_status']),
                $tranche['planned_release_date'] ?? '',
                $tranche['released_at'] ?? '',
            ];
        }

        $rows[] = [
            'TOTAL',
            '',
            $summary['tranches']['summary']['total_amount'],
            $summary['tranches']['summary']['released_amount'] . ' released',
            '',
            '',
        ];
        $rows[] = [];
        $rows[] = ['EXPENDITURE SUMMARY'];
        $rows[] = ['Bucket', 'Allocated', 'Spent', 'Remaining', 'Utilization %'];

        $ps = $this->bucket($summary['categories']['ps']);
        $mooe = $this->bucket($summary['categories']['mooe']);
        foreach ([['Personnel Services (PS)', $ps], ['MOOE', $mooe]] as [$label, $bucket]) {
            $rows[] = [$label, $bucket['allocated'], $bucket['spent'], $bucket['remaining'], Percent::csv($bucket['utilization_pct'])];
        }
        $rows[] = [
            'GRAND TOTAL',
            $summary['kpis']['total_budget'],
            $summary['kpis']['total_spent'],
            $summary['kpis']['remaining'],
            Percent::csv($summary['kpis']['utilization_pct']),
        ];
        $rows[] = [];
        $rows[] = ['RECEIPT COMPLIANCE'];
        $rows[] = ['Total expenses', $summary['compliance']['total_expenses']];
        $rows[] = ['With digitized receipt', $summary['compliance']['with_receipt']];
        $rows[] = ['Missing receipt', $summary['compliance']['missing_receipt']];
        $rows[] = ['Compliance %', $summary['compliance']['compliance_pct']];

        return $rows;
    }

    protected function utilizationRows(array $summary): array
    {
        $project = $summary['project'];
        $rows = [
            ['Budget Utilization Report'],
            ['Project Code', $project['code']],
            ['Project Title', $project['title']],
            [],
            ['Category', 'Type', 'Allocated', 'Spent', 'Remaining', 'Utilization %'],
        ];

        foreach (array_merge($summary['categories']['ps'], $summary['categories']['mooe']) as $category) {
            $rows[] = [
                $category['name'],
                $category['type'],
                $category['allocated'],
                $category['spent'],
                $category['remaining'],
                Percent::csv($category['utilization_pct']),
            ];
        }

        $rows[] = [
            'GRAND TOTAL',
            '',
            $summary['kpis']['total_budget'],
            $summary['kpis']['total_spent'],
            $summary['kpis']['remaining'],
            Percent::csv($summary['kpis']['utilization_pct']),
        ];

        return $rows;
    }

    protected function liquidationRows(Project $project, string $compliance): array
    {
        $query = $project->expenses()
            ->with(['libCategory', 'verifiedBy'])
            ->withCount('receipts')
            ->orderBy('expense_date');

        if ($compliance === 'verified') {
            ProjectFinancialService::onlySpent($query);
        } elseif ($compliance === 'missing_receipts') {
            // Verified expenses with no receipt document.
            ProjectFinancialService::missingReceipt($query);
        }

        $rows = [
            ['Liquidation / Expense Summary'],
            ['Project Code', $project->code],
            ['Project Title', $project->title],
            ['Compliance filter', $compliance],
            [],
            ['Date', 'Reference', 'Description', 'Category', 'Amount', 'Status', 'Receipt Attached', 'Verified By'],
        ];

        $total = 0.0;
        foreach ($query->get() as $expense) {
            $amount = (float) $expense->amount;
            $total += $amount;
            $rows[] = [
                $expense->expense_date?->toDateString(),
                $expense->reference_no ?? '',
                $expense->description,
                $expense->libCategory?->line_item_name ?? '',
                $amount,
                ucfirst($expense->status),
                $expense->receipts_count > 0 ? 'Yes' : 'No',
                $expense->verifiedBy?->name ?? '',
            ];
        }

        $rows[] = ['TOTAL', '', '', '', $total, '', '', ''];

        return $rows;
    }

    protected function bucket(array $categories): array
    {
        $allocated = (float) array_sum(array_column($categories, 'allocated'));
        $spent = (float) array_sum(array_column($categories, 'spent'));

        return [
            'allocated' => $allocated,
            'spent' => $spent,
            'remaining' => $allocated - $spent,
            'utilization_pct' => Percent::of($spent, $allocated),
        ];
    }

}
