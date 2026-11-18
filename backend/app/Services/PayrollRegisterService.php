<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Payroll register for reports: VERIFIED payroll batches only (the same
 * rule as verification). Draft, waiting (submitted / endorsed) and returned
 * batches never appear. One block per month, one row per person, with each
 * deduction itemized, plus monthly and overall totals.
 *
 * Periods match the Reports page: all (every verified month), year (the
 * current year), 90d (months that overlap the last 90 days), or a single
 * month (YYYY-MM, the Research Assistant's Payroll page). Dates are Manila.
 */
class PayrollRegisterService
{
    public const PERIODS = ['all' => 'All dates', 'year' => 'Current year', '90d' => 'Last 90 days'];

    /** @return array<string, mixed> */
    public function build(Project $project, string $period = 'all', ?string $month = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Asia/Manila');
        $types = $this->deductionTypes();

        $batches = PayrollBatch::query()
            ->where('project_id', $project->id)
            ->where('workflow_status', 'verified')
            ->when($month !== null, function ($q) use ($month) {
                [$year, $m] = array_map('intval', explode('-', $month));
                $q->where('pay_year', $year)->where('pay_month', $m);
            })
            ->when($month === null && $period === 'year', fn ($q) => $q->where('pay_year', $today->year))
            ->when($month === null && $period === '90d', function ($q) use ($today) {
                $from = $today->subDays(90)->startOfMonth();
                $q->where(fn ($w) => $w->where('pay_year', '>', $from->year)
                    ->orWhere(fn ($same) => $same->where('pay_year', $from->year)->where('pay_month', '>=', $from->month)));
            })
            ->with(['rows.personnel', 'rows.deductions', 'verifiedBy'])
            ->orderBy('pay_year')
            ->orderBy('pay_month')
            ->get();

        // A deduction type not in the config (e.g. an older rule) still gets its own column.
        $types = $batches->flatMap->rows->flatMap->deductions->pluck('deduction_type')
            ->reject(fn ($type) => in_array($type, $types, true))
            ->unique()->values()
            ->pipe(fn (Collection $extra) => array_merge($types, $extra->all()));

        $months = $batches->map(function (PayrollBatch $batch) use ($types) {
            $rows = $batch->rows->sortBy(fn (Payroll $row) => [$row->personnel?->name ?? '', $row->id])
                ->map(fn (Payroll $row) => $this->row($row, $types))->values()->all();

            return [
                'month' => $batch->monthKey(),
                'month_label' => $batch->monthLabel(),
                'batch_id' => $batch->id,
                'verified_at' => $batch->verified_at?->toIso8601String(),
                'verified_by' => $batch->verifiedBy?->name,
                'rows' => $rows,
                'totals' => $this->totals($rows, $types),
            ];
        })->values()->all();

        return [
            'project' => ['id' => $project->id, 'code' => $project->code, 'title' => $project->title],
            'period' => $month ?? $period,
            'period_label' => $month !== null ? ($months[0]['month_label'] ?? $month) : self::PERIODS[$period],
            'rates_label' => config('payroll.rates_label'),
            'deduction_types' => $types,
            'months' => $months,
            'totals' => $this->totals(array_merge(...array_column($months, 'rows') ?: [[]]), $types),
        ];
    }

    /** CSV rows: the label (when set) first, then one block per month with its total, then the grand total. */
    public function csvRows(array $register): array
    {
        $types = $register['deduction_types'];
        $rows = [];
        if ($register['rates_label']) {
            $rows[] = ['⚠ ' . $register['rates_label']];
            $rows[] = [];
        }
        $rows[] = ['Payroll register'];
        $rows[] = ['Project', $register['project']['title'] . ' (' . $register['project']['code'] . ')'];
        $rows[] = ['Period', $register['period_label']];
        $rows[] = ['Source', 'Verified payroll batches only'];
        $rows[] = [];
        $rows[] = array_merge(['Month', 'Name', 'Position', 'Monthly rate', 'Allowance', 'Gross pay'], $types, ['Total deductions', 'Net pay']);

        $money = fn ($value) => number_format((float) $value, 2, '.', '');
        $line = fn (string $month, string $name, string $position, array $r) => array_merge(
            [$month, $name, $position, $money($r['monthly_rate']), $money($r['allowance']), $money($r['gross'])],
            array_map(fn ($type) => $money($r['deductions'][$type] ?? 0), $types),
            [$money($r['total_deductions']), $money($r['net'])],
        );

        foreach ($register['months'] as $month) {
            foreach ($month['rows'] as $row) {
                $rows[] = $line($month['month_label'], (string) $row['name'], (string) $row['position'], $row);
            }
            $rows[] = $line($month['month_label'], 'Month total', '', $month['totals']);
        }
        $rows[] = $line('GRAND TOTAL', '', '', $register['totals']);

        return $rows;
    }

    /** @return array<int, string> Deduction types from config/payroll.php, in order. */
    private function deductionTypes(): array
    {
        return collect(config('payroll.deductions', []))->pluck('type')->filter()->values()->all()
            ?: ['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax'];
    }

    private function row(Payroll $row, array $types): array
    {
        $byType = $row->deductions->groupBy('deduction_type')->map(fn ($items) => round((float) $items->sum('amount'), 2));

        return [
            'id' => $row->id,
            'name' => $row->personnel?->name,
            'position' => $row->personnel?->position,
            'monthly_rate' => round((float) $row->basic_rate, 2),
            'allowance' => round((float) $row->allowance, 2),
            'gross' => round((float) $row->gross_pay, 2),
            'deductions' => collect($types)->mapWithKeys(fn ($type) => [$type => (float) ($byType[$type] ?? 0)])->all(),
            'total_deductions' => round((float) $row->total_deductions, 2),
            'net' => round((float) $row->net_pay, 2),
        ];
    }

    private function totals(array $rows, array $types): array
    {
        $sum = fn (callable $pick) => round(array_sum(array_map($pick, $rows)), 2);

        return [
            'people' => count($rows),
            'monthly_rate' => $sum(fn ($r) => $r['monthly_rate']),
            'allowance' => $sum(fn ($r) => $r['allowance']),
            'gross' => $sum(fn ($r) => $r['gross']),
            'deductions' => collect($types)->mapWithKeys(fn ($type) => [$type => $sum(fn ($r) => $r['deductions'][$type] ?? 0)])->all(),
            'total_deductions' => $sum(fn ($r) => $r['total_deductions']),
            'net' => $sum(fn ($r) => $r['net']),
        ];
    }
}
