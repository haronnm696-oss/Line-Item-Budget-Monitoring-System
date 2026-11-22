<?php

namespace App\Services;

use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * One purchase request printed in MSU's Purchase Request form. The PDF
 * (frontend print view, lib/purchaseRequestPrint.js) and the CSV both
 * render this one document.
 *
 *   PURCHASE REQUEST / Mindanao State University / Marawi City
 *   Office/Unit (implementing agency), Department (the Project Leader's department, college)
 *   PR No. / Date, SAI No. / Date, ALOBS No. / Date (blank when not set)
 *   | Item No. | Qty | Unit | Item Description | Estimated Unit Cost | Estimated Cost |  TOTAL
 *   Purpose/Remarks; CHARGEABLE AGAINST <FUNDER> FUNDED PROJECT; project title
 *   Requested by (the Project Leader) / Noted by / Approved by (per project, Edit project details)
 *
 * A request not approved yet (draft, submitted, returned) is marked DRAFT.
 */
class PurchaseRequestFormService
{
    public const TITLE = 'PURCHASE REQUEST';

    public const UNIVERSITY = 'Mindanao State University';

    public const CITY = 'Marawi City';

    public const COLUMNS = ['Item No.', 'Qty', 'Unit', 'Item Description', 'Estimated Unit Cost', 'Estimated Cost'];

    /** Not approved yet: printed with a DRAFT watermark. A cancelled request was approved first. */
    public const DRAFT_STAGES = ['draft', 'submitted', 'returned'];

    /** Who may print it: the project's Research Assistant, Project Leader, Finance/Admin, or the Program Leader. */
    public const PRINT_ROLES = ['research-assistant', 'project-leader', 'finance-admin'];

    public static function canPrint(User $user, PurchaseRequest $request): bool
    {
        return $user->isProgramLeader() || ($request->project && $user->hasRoleOn($request->project, self::PRINT_ROLES));
    }

    /** @return array<string, mixed> */
    public function build(PurchaseRequest $request): array
    {
        $request->loadMissing(['project.fundingAgency', 'items']);
        $project = $request->project;
        $leader = $project->usersWithRole('project-leader')->first();
        $reference = $this->text($request->pr_no) ?? "PR-{$request->id}";
        $funder = $project->fundingAgency ? ProjectFinancialService::sourceLabels($project)['funder'] : null;

        $rows = [];
        foreach ($request->lineItems()->values() as $index => $item) {
            /** @var PurchaseRequestItem $item */
            $rows[] = [
                'item_no' => $index + 1,
                'quantity' => (int) $item->quantity,
                'unit' => (string) $item->unit,
                'description' => (string) $item->description,
                'unit_cost' => round((float) $item->unit_cost, 2),
                'estimated_cost' => round((float) $item->estimated_cost, 2),
            ];
        }
        $total = round(array_sum(array_column($rows, 'estimated_cost')), 2);

        return [
            'id' => $request->id,
            'project_code' => (string) $project->code,
            'reference' => $reference,
            // "PR - <project code> - <PR No. or id>": the print title, so a saved PDF gets that name.
            'title' => 'PR - ' . $project->code . ' - ' . ($this->text($request->pr_no) ?? $request->id),
            'draft' => in_array($request->workflow_status, self::DRAFT_STAGES, true),
            'workflow_status' => $request->workflow_status,
            'header' => ['title' => self::TITLE, 'university' => self::UNIVERSITY, 'city' => self::CITY],
            'office_unit' => ProjectFinancialService::implementingAgencyName($project),
            // The Project Leader's (the "Requested by"), never the person who prepared it.
            'department' => $this->department($leader) ?? '',
            'numbers' => [
                $this->number('PR No.', $request->pr_no, $request->pr_date),
                $this->number('SAI No.', $request->sai_no, $request->sai_date),
                $this->number('ALOBS No.', $request->alobs_no, $request->alobs_date),
            ],
            'columns' => self::COLUMNS,
            'rows' => $rows,
            'total' => $total,
            'purpose' => (string) $request->description,
            'chargeable' => $funder !== null
                ? 'CHARGEABLE AGAINST ' . mb_strtoupper($funder) . ' FUNDED PROJECT'
                : 'CHARGEABLE AGAINST FUNDED PROJECT',
            'project_title' => (string) $project->title,
            'signatories' => $this->signatories($project, $leader),
        ];
    }

    /** The same document as CSV rows: plain numbers ("80000.00"). */
    public function csvRows(array $doc): array
    {
        $out = [[$doc['header']['title']], [$doc['header']['university']], [$doc['header']['city']]];
        if ($doc['draft']) {
            $out[] = ['DRAFT', 'Not approved yet'];
        }
        $out[] = [];
        $out[] = ['Office/Unit:', $doc['office_unit']];
        $out[] = ['Department:', $doc['department']];
        foreach ($doc['numbers'] as $number) {
            $out[] = [$number['label'], $number['value'] ?? '', 'Date:', $number['date'] ?? ''];
        }
        $out[] = [];
        $out[] = $doc['columns'];
        foreach ($doc['rows'] as $row) {
            $out[] = [
                $row['item_no'], $row['quantity'], $row['unit'], $row['description'],
                FormBReportService::csvAmount($row['unit_cost']), FormBReportService::csvAmount($row['estimated_cost']),
            ];
        }
        $out[] = ['TOTAL', '', '', '', '', FormBReportService::csvAmount($doc['total'])];
        $out[] = [];
        $out[] = ['Purpose/Remarks:', $doc['purpose']];
        $out[] = [$doc['chargeable']];
        $out[] = [$doc['project_title']];
        $out[] = [];
        foreach ($doc['signatories'] as $signatory) {
            $out[] = array_values(array_filter([$signatory['label'], $signatory['name'], $signatory['position']], fn ($v) => $v !== null));
        }

        return $out;
    }

    /** Requested by = the project's Project Leader, always; Noted by / Approved by from the project. */
    private function signatories(Project $project, ?User $leader): array
    {
        $out = [];
        foreach (Project::PR_SIGNATORIES as $prefix => $label) {
            $out[] = [
                'label' => $label,
                'name' => $prefix === 'pr_requested_by' ? $this->text($leader?->name) : $this->text($project->{"{$prefix}_name"}),
                'position' => $this->text($project->{"{$prefix}_position"}),
            ];
        }

        return $out;
    }

    /** "Department of Chemistry, College of Natural Sciences and Mathematics", or null. */
    private function department(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $parts = array_values(array_filter([$this->text($user->department), $this->text($user->college)]));

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    private function number(string $label, $value, ?CarbonInterface $date): array
    {
        return ['label' => $label, 'value' => $this->text($value), 'date' => $date?->format('F j, Y')];
    }

    private function text($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
