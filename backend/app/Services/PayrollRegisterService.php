<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\Project;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Payroll register for reports: VERIFIED payroll batches only (the same
 * rule as verification). Draft, waiting (submitted / endorsed) and returned
 * batches never appear. One block per pay period, one row per person with
 * the computation "(8,800 x 3 months) = 26,400.00", plus period and overall
 * totals. Project honoraria have no deductions and no allowances: net =
 * gross. Batches verified before that rule stay as recorded; when any of
 * them carries deductions, `has_recorded_deductions` is true and a
 * "Recorded deductions" column is shown for them.
 *
 * Periods match the Reports page: all (every verified batch), year (batches
 * sharing a month with the current year), 90d (batches sharing a month with
 * the last 90 days), or a single month (YYYY-MM: the batch covering it, the
 * Research Assistant's Payroll page). Dates are Manila.
 */
class PayrollRegisterService
{
    public const PERIODS = ['all' => 'All dates', 'year' => 'Current year', '90d' => 'Last 90 days'];

    /** @return array<string, mixed> */
    public function build(Project $project, string $period = 'all', ?string $month = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Asia/Manila');
        [$from, $to] = $this->window($period, $month, $today);

        $batches = PayrollBatch::query()
            ->where('project_id', $project->id)
            ->where('workflow_status', 'verified')
            ->with(['rows.personnel', 'verifiedBy'])
            ->orderBy('pay_year')
            ->orderBy('pay_month')
            ->get()
            ->filter(fn (PayrollBatch $batch) => $from === null || $batch->overlaps($from, $to))
            ->values();

        $months = $batches->map(function (PayrollBatch $batch) {
            $rows = $batch->rows->sortBy(fn (Payroll $row) => [$row->personnel?->name ?? '', $row->id])
                ->map(fn (Payroll $row) => $this->row($row))->values()->all();

            return [
                'month' => $batch->monthKey(),
                'month_label' => $batch->monthLabel(),
                'months' => $batch->monthCount(),
                'batch_id' => $batch->id,
                'verified_at' => $batch->verified_at?->toIso8601String(),
                'verified_by' => $batch->verifiedBy?->name,
                'rows' => $rows,
                'totals' => $this->totals($rows),
            ];
        })->values()->all();

        $allRows = array_merge(...array_column($months, 'rows') ?: [[]]);

        return [
            'project' => ['id' => $project->id, 'code' => $project->code, 'title' => $project->title],
            'period' => $month ?? $period,
            'period_label' => $month !== null ? ($months[0]['month_label'] ?? $month) : self::PERIODS[$period],
            'has_recorded_deductions' => collect($allRows)->contains(fn ($r) => $r['recorded_deductions'] > 0),
            'months' => $months,
            'totals' => $this->totals($allRows),
        ];
    }

    /** CSV rows: one block per pay period with its total, then the grand total. */
    public function csvRows(array $register): array
    {
        $legacy = $register['has_recorded_deductions'];
        $rows = [];
        $rows[] = ['Payroll register'];
        $rows[] = ['Project', $register['project']['title'] . ' (' . $register['project']['code'] . ')'];
        $rows[] = ['Period', $register['period_label']];
        $rows[] = ['Source', 'Verified payroll batches only. Project honoraria have no deductions and no allowances.'];
        $rows[] = [];
        $rows[] = array_merge(['Pay period', 'Name', 'Position', 'Monthly honorarium', 'Months', 'Computation', 'Gross pay'], $legacy ? ['Recorded deductions'] : [], ['Net pay']);

        $money = fn ($value) => number_format((float) $value, 2, '.', '');
        $line = fn (string $period, string $name, string $position, array $r, bool $total = false) => array_merge(
            [$period, $name, $position, $total ? '' : $money($r['monthly_rate']), $total ? '' : (string) $r['months'], $total ? '' : $r['computation'], $money($r['gross'])],
            $legacy ? [$money($r['recorded_deductions'])] : [],
            [$money($r['net'])],
        );

        foreach ($register['months'] as $month) {
            foreach ($month['rows'] as $row) {
                $rows[] = $line($month['month_label'], (string) $row['name'], (string) $row['position'], $row);
            }
            $rows[] = $line($month['month_label'], 'Period total', '', $month['totals'], true);
        }
        $rows[] = $line('GRAND TOTAL', '', '', $register['totals'], true);

        return $rows;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} first days of the first and last month, or nulls (all). */
    private function window(string $period, ?string $month, CarbonImmutable $today): array
    {
        if ($month !== null) {
            [$year, $m] = array_map('intval', explode('-', $month));
            $start = Carbon::create($year, $m, 1)->startOfDay();

            return [$start, $start->copy()];
        }

        return match ($period) {
            'year' => [Carbon::create($today->year, 1, 1)->startOfDay(), Carbon::create($today->year, 12, 1)->startOfDay()],
            '90d' => [Carbon::parse($today->subDays(90)->startOfMonth()->toDateString())->startOfDay(), Carbon::create($today->year, $today->month, 1)->startOfDay()],
            default => [null, null],
        };
    }

    private function row(Payroll $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->personnel?->name,
            'position' => $row->personnel?->position,
            'monthly_rate' => round((float) $row->basic_rate, 2),
            'months' => $row->monthsPaid(),
            'computation' => $row->computationText(),
            'gross' => round((float) $row->gross_pay, 2),
            'recorded_deductions' => round((float) $row->total_deductions, 2),
            'net' => round((float) $row->net_pay, 2),
        ];
    }

    private function totals(array $rows): array
    {
        $sum = fn (callable $pick) => round(array_sum(array_map($pick, $rows)), 2);

        return [
            'people' => count($rows),
            'gross' => $sum(fn ($r) => $r['gross']),
            'recorded_deductions' => $sum(fn ($r) => $r['recorded_deductions']),
            'net' => $sum(fn ($r) => $r['net']),
        ];
    }
}
