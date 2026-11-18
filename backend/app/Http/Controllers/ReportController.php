<?php

namespace App\Http\Controllers;

use App\Support\Percent;
use App\Services\ProjectFinancialService;
use App\Models\Project;
use App\Services\DashboardService;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /**
     * Export official monitoring reports as CSV. Supported types:
     * form-b (LIB), form-c (financial/fund-release), utilization, liquidation.
     */
    public function export(Request $request, Project $project, string $type)
    {
        $type = strtolower($type);
        $allowed = ['form-b', 'form-c', 'utilization', 'liquidation'];

        abort_unless(in_array($type, $allowed, true), 404, 'Unknown report type.');

        $compliance = (string) $request->query('compliance', 'all');
        $summary = $this->dashboard->build($project);

        $rows = match ($type) {
            'form-b' => $this->formBRows($summary),
            'form-c' => $this->formCRows($summary),
            'utilization' => $this->utilizationRows($summary),
            'liquidation' => $this->liquidationRows($project, $compliance),
        };

        $filename = Str::slug($project->code . '-' . $type . '-' . now()->format('Ymd')) . '.csv';

        return response(Csv::render($rows), 200, Csv::headers($filename));
    }

    protected function formBRows(array $summary): array
    {
        $project = $summary['project'];
        $rows = [
            ['MOST R&D Form B — Line-Item Budget Monitoring'],
            ['Project Code', $project['code']],
            ['Project Title', $project['title']],
            ['Implementing Agency', $project['implementing_agency']],
            ['Funding Agency', $project['funding_agency']],
            ['Project Leader', $project['project_leader']],
            ['Project Period', $project['period']],
            [],
            ['Category', 'Type', 'Line Item Code', 'Allocated', 'Spent', 'Remaining', 'Utilization %'],
        ];

        foreach (array_merge($summary['categories']['ps'], $summary['categories']['mooe']) as $category) {
            $rows[] = [
                $category['name'],
                $category['type'],
                $category['code'] ?? '',
                $category['allocated'],
                $category['spent'],
                $category['remaining'],
                \App\Support\Percent::label($category['utilization_pct'], ''),
            ];
        }

        $rows[] = [
            'GRAND TOTAL',
            '',
            '',
            $summary['kpis']['total_budget'],
            $summary['kpis']['total_spent'],
            $summary['kpis']['remaining'],
            \App\Support\Percent::label($summary['kpis']['utilization_pct'], ''),
        ];

        return $rows;
    }

    protected function formCRows(array $summary): array
    {
        $project = $summary['project'];
        $rows = [
            ['MOST R&D Form C — Financial and Fund-Release Report'],
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
            $rows[] = [$label, $bucket['allocated'], $bucket['spent'], $bucket['remaining'], \App\Support\Percent::label($bucket['utilization_pct'], '')];
        }
        $rows[] = [
            'GRAND TOTAL',
            $summary['kpis']['total_budget'],
            $summary['kpis']['total_spent'],
            $summary['kpis']['remaining'],
            \App\Support\Percent::label($summary['kpis']['utilization_pct'], ''),
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
                \App\Support\Percent::label($category['utilization_pct'], ''),
            ];
        }

        $rows[] = [
            'GRAND TOTAL',
            '',
            $summary['kpis']['total_budget'],
            $summary['kpis']['total_spent'],
            $summary['kpis']['remaining'],
            \App\Support\Percent::label($summary['kpis']['utilization_pct'], ''),
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
            'utilization_pct' => \App\Support\Percent::of($spent, $allocated),
        ];
    }

}
