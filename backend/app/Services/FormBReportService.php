<?php

namespace App\Services;

use App\Models\Project;
use App\Support\LineItemComputation;
use Carbon\CarbonInterface;

/**
 * The printable R&D Form B (Project Line-Item Budget), laid out like the
 * official form for ANY funder: only the funder's short and full names
 * change (never a hard-coded funder). Built from ProjectFinancialService::formB()
 * so the amounts never drift from the screens. The PDF (frontend print view)
 * and the CSV export both render this one document.
 *
 *   <short> R&D Form B / <FUNDER FULL NAME> / Project Line-Item Budget / CY <year>
 *   Project Title, Implementing Agency, Total Duration, Project Leader, Monitoring Agency
 *   | item | Counterpart Funding: Implementing Agency | <short> |
 *   I. PERSONNEL SERVICES (PS) ... Sub-total for PS
 *   II. MAINTENANCE AND OTHER OPERATING EXPENSES (MOOE) ... Sub-Total for MOOE
 *   GRAND TOTAL
 *   (To be filled-up by <short>)  Implementing | <short> | TOTAL   * Chargeable against <fund source> (else the CY <year>)
 *   Reviewed by / Certified Funds Available / Recommending Approval / Approved
 */
class FormBReportService
{
    /** Body rows that still fit one portrait A4 page; longer forms use Legal. */
    public const A4_MAX_ROWS = 30;

    private const SECTIONS = [
        'PS' => ['I. PERSONNEL SERVICES (PS)', 'Sub-total for PS'],
        'MOOE' => ['II. MAINTENANCE AND OTHER OPERATING EXPENSES (MOOE)', 'Sub-Total for MOOE'],
    ];

    private const COST_LABELS = [
        'Direct' => 'Direct Cost',
        'Indirect' => 'Indirect Cost (Implementing Agency)',
    ];

    public function __construct(private ProjectFinancialService $financials)
    {
    }

    /**
     * rows: [{ kind: section|cost|group|subgroup|item|subtotal|grand, label,
     * level (indent), implementing_agency: float|null, funder: float|null }];
     * null amounts are blank cells (headings), 0 prints as "-".
     *
     * @return array<string, mixed>
     */
    public function build(Project $project): array
    {
        $project->loadMissing('fundingAgency');
        $formB = $this->financials->formB($project);
        $labels = $formB['labels'];
        $short = $labels['funder'];
        $fullName = trim((string) ($labels['funder_name'] ?? '')) ?: $short;
        $sections = collect($formB['sections'])->keyBy('type');
        $zero = ['implementing_agency' => 0.0, 'funder' => 0.0, 'total' => 0.0];

        $rows = [];
        foreach (self::SECTIONS as $type => [$heading, $subtotalLabel]) {
            $section = $sections[$type] ?? null;
            $rows[] = $this->row('section', $heading, 0);
            foreach ($section['cost_types'] ?? [] as $cost) {
                $rows[] = $this->row('cost', self::COST_LABELS[$cost['cost_type']] ?? $cost['label'], 1);
                foreach ($cost['groups'] as $group) {
                    array_push($rows, ...$this->groupRows($group));
                }
            }
            $rows[] = $this->row('subtotal', $subtotalLabel, 0, $section['subtotal'] ?? $zero);
        }
        $grand = $formB['grand_total'];
        $rows[] = $this->row('grand', 'GRAND TOTAL', 0, $grand);

        $signatories = [];
        foreach (Project::FORM_B_SIGNATORIES as $prefix => $label) {
            $signatories[] = [
                'label' => $label,
                'name' => $this->text($project->{"{$prefix}_name"}),
                'position' => $this->text($project->{"{$prefix}_position"}),
            ];
        }

        $year = $project->start_date?->year ?? now()->year;

        return [
            'funder' => ['short_name' => $short, 'name' => $fullName],
            'header' => [
                'form' => "{$short} R&D Form B",
                'agency' => mb_strtoupper($fullName),
                'title' => 'Project Line-Item Budget',
                'year' => "CY {$year}",
            ],
            'details' => [
                ['label' => 'Project Title', 'value' => (string) $project->title],
                ['label' => 'Implementing Agency', 'value' => $labels['implementing_agency']],
                ['label' => 'Total Duration', 'value' => $this->duration($project->start_date, $project->end_date)],
                ['label' => 'Project Leader', 'value' => $project->usersWithRole('project-leader')->first()?->name ?? ''],
                ['label' => 'Monitoring Agency', 'value' => $this->text($project->monitoring_agency) ?? $fullName],
            ],
            'columns' => ['', 'Counterpart Funding: Implementing Agency', $short],
            'rows' => $rows,
            'footer' => [
                'title' => "(To be filled-up by {$short})",
                'columns' => ['Implementing', $short, 'TOTAL'],
                // Pre-filled, as on the official form.
                'values' => [$grand['implementing_agency'], $grand['funder'], $grand['total']],
                // The MOA's fund source when set, else the calendar year.
                'note' => ($source = $this->text($project->fund_source)) !== null
                    ? "* Chargeable against {$source}"
                    : "* Chargeable against the CY {$year}",
            ],
            'signatories' => $signatories,
            'paper' => count($rows) <= self::A4_MAX_ROWS ? 'A4' : 'Legal',
        ];
    }

    /** The same document as CSV rows: plain numbers, zero as "0.00". */
    public function csvRows(array $doc): array
    {
        $out = [[$doc['header']['form']], [$doc['header']['agency']], [$doc['header']['title']], [$doc['header']['year']], []];
        foreach ($doc['details'] as $detail) {
            $out[] = [$detail['label'] . ':', $detail['value']];
        }
        $out[] = [];
        $out[] = $doc['columns'];
        foreach ($doc['rows'] as $row) {
            $hasAmounts = $row['implementing_agency'] !== null || $row['funder'] !== null;
            $out[] = $hasAmounts
                ? [str_repeat('  ', $row['level']) . $row['label'], self::csvAmount($row['implementing_agency']), self::csvAmount($row['funder'])]
                : [str_repeat('  ', $row['level']) . $row['label']];
        }
        $out[] = [];
        $out[] = [$doc['footer']['title']];
        $out[] = $doc['footer']['columns'];
        $out[] = array_merge(array_map([self::class, 'csvAmount'], $doc['footer']['values']), ['', $doc['footer']['note']]);
        $out[] = [];
        foreach ($doc['signatories'] as $signatory) {
            $out[] = array_values(array_filter([$signatory['label'], $signatory['name'], $signatory['position']], fn ($v) => $v !== null));
        }

        return $out;
    }

    /**
     * Plain numbers only ("1058376.00", zero as "0.00") so spreadsheet
     * formulas work; blank for a heading. The PDF prints zero as "-".
     */
    public static function csvAmount(?float $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return number_format(abs($amount) < 0.005 ? 0 : $amount, 2, '.', '');
    }

    /** "Twelve (12) months" from the project period (whole months, rounded up). */
    public function duration(?CarbonInterface $start, ?CarbonInterface $end): string
    {
        if (! $start || ! $end || $end->lt($start)) {
            return '';
        }
        $until = $end->copy()->addDay();
        $months = $start->diffInMonths($until);
        if ($start->copy()->addMonthsNoOverflow($months)->lt($until)) {
            $months++;
        }
        $months = max(1, $months);

        return ucfirst(LineItemComputation::words($months)) . " ({$months}) " . ($months === 1 ? 'month' : 'months');
    }

    /** A group heading (italics), its items, then its sub-groups and their items. */
    private function groupRows(array $group): array
    {
        $items = $group['items'];
        // A lone item named like its group prints on the group line itself.
        if ($group['subgroups'] === [] && count($items) === 1 && $items[0]['computation'] === null
            && strcasecmp(trim($items[0]['name']), trim($group['name'])) === 0) {
            return [$this->row('group', $group['name'], 2, $this->amounts($items[0]))];
        }

        $rows = [$this->row('group', $group['name'], 2)];
        foreach ($items as $item) {
            $rows[] = $this->row('item', $this->itemLabel($item), 3, $this->amounts($item));
        }
        foreach ($group['subgroups'] as $sub) {
            $rows[] = $this->row('subgroup', $sub['name'], 3);
            foreach ($sub['items'] as $item) {
                $rows[] = $this->row('item', $this->itemLabel($item), 4, $this->amounts($item));
            }
        }

        return $rows;
    }

    /** The computation as on the official form ("@ P8,800.00/mo."), else the name. */
    private function itemLabel(array $item): string
    {
        return $item['computation'] !== null ? str_replace('₱', 'P', $item['computation']) : $item['name'];
    }

    private function amounts(array $item): array
    {
        return ['implementing_agency' => $item['implementing_agency_amount'], 'funder' => $item['funder_amount']];
    }

    private function row(string $kind, string $label, int $level, ?array $amounts = null): array
    {
        return [
            'kind' => $kind,
            'label' => $label,
            'level' => $level,
            'implementing_agency' => $amounts !== null ? round((float) $amounts['implementing_agency'], 2) : null,
            'funder' => $amounts !== null ? round((float) $amounts['funder'], 2) : null,
        ];
    }

    private function text($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
