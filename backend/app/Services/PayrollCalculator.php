<?php

namespace App\Services;

use App\Models\ProjectPersonnel;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One person's honorarium for a pay period. Project honoraria have NO
 * deductions and NO allowances:
 *
 *   amount = monthly honorarium x months the appointment is effective
 *            within the period;  net = gross = amount
 *
 * A month counts when a personnel record (Special Order) of the person
 * covers any day of it (ProjectPersonnel::coversMonth). When two records
 * touch the same month (an appointment renewed mid-month) the month is paid
 * once, at the newer record's honorarium. A month whose honorarium is 0.00
 * is not paid.
 */
class PayrollCalculator
{
    /**
     * @param  Collection<int, ProjectPersonnel>  $records  one person's records
     * @return array{segments: array<int, array{honorarium: float, months: int}>, months: int, basic: float, gross: float, net: float}
     */
    public function forPeriod(Collection $records, Carbon $from, Carbon $to): array
    {
        $records = $records->sortByDesc(fn (ProjectPersonnel $r) => [$r->effective_from?->timestamp ?? 0, $r->id])->values();
        $segments = [];

        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonthNoOverflow()) {
            $record = $records->first(fn (ProjectPersonnel $r) => $r->coversMonth($month->year, $month->month));
            $honorarium = $record ? round((float) $record->monthly_honorarium, 2) : 0.0;
            if ($honorarium <= 0) {
                continue;
            }
            $key = number_format($honorarium, 2, '.', '');
            $segments[$key] ??= ['honorarium' => $honorarium, 'months' => 0];
            $segments[$key]['months']++;
        }

        return $this->fromSegments(array_values($segments));
    }

    /**
     * @param  array<int, array{honorarium: float, months: int}>  $segments
     * @return array{segments: array<int, array{honorarium: float, months: int}>, months: int, basic: float, gross: float, net: float}
     */
    public function fromSegments(array $segments): array
    {
        $segments = array_values(array_filter(
            array_map(fn ($s) => ['honorarium' => round(max(0, (float) $s['honorarium']), 2), 'months' => max(0, (int) $s['months'])], $segments),
            fn ($s) => $s['honorarium'] > 0 && $s['months'] > 0
        ));
        $gross = round(array_sum(array_map(fn ($s) => $s['honorarium'] * $s['months'], $segments)), 2);

        return [
            'segments' => $segments,
            'months' => array_sum(array_column($segments, 'months')),
            // The monthly honorarium (the latest one when it changed in the period).
            'basic' => $segments === [] ? 0.0 : end($segments)['honorarium'],
            'gross' => $gross,
            'net' => $gross,
        ];
    }
}
