<?php

namespace App\Services;

/**
 * Renders the Program Leader projects list as an Excel 2003 XML workbook
 * (SpreadsheetML). Excel opens it natively with typed number cells, and it
 * needs no PHP extension or package beyond core string functions.
 *
 * Every value comes from ProgramDashboardService::projectList(); this class
 * only lays the numbers out.
 */
class ProjectListSpreadsheet
{
    private const MISSING = '—';

    /**
     * @param array<string, mixed> $list  ProgramDashboardService::projectList() payload
     */
    public function render(array $list, string $filterLabel): string
    {
        $rows = [];
        $rows[] = [['Program projects', 'title']];
        $rows[] = [['Generated ' . now()->format('Y-m-d H:i') . ' · ' . $filterLabel, 'muted']];
        $rows[] = [];

        $header = [
            'Project', 'Code', 'Funder reference', 'Funder', 'Project leader',
            'Approved budget', 'Spent', 'Spent %', 'PS spent %', 'MOOE spent %', 'Remaining',
            'Start', 'End', 'Last entry', 'Status', 'Health', 'Health note', 'Pending approvals',
        ];
        $rows[] = array_map(fn ($label) => [$label, 'head'], $header);

        foreach ($list['projects'] as $project) {
            $closed = ($project['health'] ?? null) === 'Closed';

            $rows[] = [
                $this->text($project['title'] ?? null),
                $this->text($project['code'] ?? null),
                $this->text($project['funder_reference'] ?? null),
                $this->text($project['funder'] ?? null),
                $this->text($project['project_leader'] ?? null),
                $this->money($project['budget'] ?? null),
                $this->money($project['spent'] ?? null),
                $this->percent($project['spent_pct'] ?? null),
                $this->percent($project['ps']['utilization_pct'] ?? null),
                $this->percent($project['mooe']['utilization_pct'] ?? null),
                $this->money($project['remaining'] ?? null),
                $this->text($project['start_date'] ?? null),
                $this->text($project['end_date'] ?? null),
                $this->text($project['last_entry_date'] ?? null, 'none yet'),
                $this->text($project['status'] ?? null),
                $this->text($closed ? null : ($project['health'] ?? null)),
                $this->text($project['health_note'] ?? null),
                [(int) ($project['pending_approvals'] ?? 0), 'int'],
            ];
        }

        $summary = $list['summary'];
        $rows[] = [];
        $rows[] = [['Summary', 'head']];
        $rows[] = [['Projects', 'label'], [(int) $summary['projects'], 'int'],
            [$summary['ongoing'] . ' ongoing · ' . $summary['completed'] . ' completed', 'muted']];
        $rows[] = [['Total allocated', 'label'], $this->money($summary['allocated'])];
        $rows[] = [['Total spent', 'label'], $this->money($summary['spent']),
            [$this->pctText($summary['spent_pct']) . ' of allocated', 'muted']];
        $rows[] = [['Remaining', 'label'], $this->money($summary['remaining']),
            [$this->pctText($summary['remaining_pct']) . ' still available', 'muted']];

        return $this->workbook($rows, count($header));
    }

    /** @return array{0: string, 1: string} */
    private function text($value, string $fallback = self::MISSING): array
    {
        $value = is_string($value) ? trim($value) : $value;

        return [($value === null || $value === '') ? $fallback : (string) $value, 'text'];
    }

    /** @return array{0: float|string, 1: string} */
    private function money($value): array
    {
        return $value === null ? [self::MISSING, 'text'] : [(float) $value, 'money'];
    }

    /** Stored as a fraction so Excel's percent format shows 42.8%. */
    private function percent($value): array
    {
        return $value === null ? [self::MISSING, 'text'] : [round((float) $value / 100, 4), 'pct'];
    }

    private function pctText($value): string
    {
        return number_format((float) $value, 1) . '%';
    }

    /**
     * @param array<int, array<int, array{0: mixed, 1: string}>> $rows
     */
    private function workbook(array $rows, int $columns): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Styles>'
            . '<Style ss:ID="title"><Font ss:Bold="1" ss:Size="14" ss:Color="#6F1D2A"/></Style>'
            . '<Style ss:ID="muted"><Font ss:Color="#6B5F5F"/></Style>'
            . '<Style ss:ID="head"><Font ss:Bold="1"/><Interior ss:Color="#F3EDE3" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="label"><Font ss:Bold="1"/></Style>'
            . '<Style ss:ID="text"/>'
            . '<Style ss:ID="money"><NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/></Style>'
            . '<Style ss:ID="pct"><NumberFormat ss:Format="0.0%"/></Style>'
            . '<Style ss:ID="int"><NumberFormat ss:Format="0"/></Style>'
            . '</Styles>'
            . '<Worksheet ss:Name="Projects"><Table>';

        $widths = [260, 120, 130, 110, 160, 110, 110, 70, 80, 85, 110, 80, 80, 80, 85, 75, 220, 110];
        foreach (array_slice($widths, 0, $columns) as $width) {
            $xml .= '<Column ss:Width="' . $width . '"/>';
        }

        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($row as [$value, $style]) {
                $numeric = in_array($style, ['money', 'pct', 'int'], true) && is_numeric($value);
                $xml .= '<Cell ss:StyleID="' . $style . '"><Data ss:Type="' . ($numeric ? 'Number' : 'String') . '">'
                    . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }

        return $xml . '</Table></Worksheet></Workbook>';
    }
}
