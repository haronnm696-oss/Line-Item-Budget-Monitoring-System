<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\PurchaseRequest;
use App\Services\PayrollService;
use App\Models\LibCategory;
use App\Models\Liquidation;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Support\ReleaseWording;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns raw audit entries into readable rows for the Audit trail.
 *
 * All names (people, roles, projects, line items, records) are loaded in a
 * fixed number of batched queries per page, so presenting 50 entries costs
 * the same as presenting one.
 */
class AuditEntryPresenter
{
    public const TIMEZONE = 'Asia/Manila';

    private const TYPE_LABELS = [
        'ApprovalRequest' => 'Approval request',
        'Expense' => 'Expense',
        'LibCategory' => 'Line item',
        'ProjectTranche' => 'Release',
        'Payroll' => 'Payroll',
        'Project' => 'Project',
        'User' => 'User account',
        'ProjectUser' => 'Project assignment',
        'Liquidation' => 'Liquidation',
        'ProjectReport' => 'Project report',
        'Document' => 'Document',
        'PurchaseRequest' => 'Purchase request',
        'PayrollBatch' => 'Payroll',
    ];

    /** Labels for the ACTION filter (no record type known). */
    public const ACTION_LABELS = [
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'created' => 'Added',
        'updated' => 'Edited',
        'deleted' => 'Deleted',
        'verified' => 'Verified',
        'submitted' => 'Expense submitted',
        'endorsed' => 'Approved by Project Leader',
        'returned' => 'Returned',
        'computed' => 'Payroll computed',
        'fulfilled' => 'Fulfilled',
        'cancelled' => 'Cancelled',
        'reopened' => 'Reopened',
        'pay_rate_changed' => 'Pay rate changed',
        'requested' => 'Submitted',
        'resubmitted' => 'Resubmitted',
        'withdrawn' => 'Withdrawn',
        'budget_changed' => 'Budget changed',
        'assignment_created' => 'Assigned to project',
        'assignment_updated' => 'Project role changed',
        'assignment_removed' => 'Removed from project',
        'demo_assignment_updated' => 'Assignment updated',
        'activated' => 'Account activated',
        'deactivated' => 'Account deactivated',
        'role_changed' => 'Role changed',
        'rerouted' => 'Routed past the Project Leader step',
        'Password reset' => 'Password reset',
    ];

    private const APPROVAL_TYPES = [
        'disbursement' => 'Fund release',
        'budget_realignment' => 'Realignment',
        'liquidation' => 'Liquidation',
        'payroll' => 'Payroll',
    ];

    private const FIELD_LABELS = [
        'status' => 'Status',
        'role_id' => 'Role',
        'role' => 'Role',
        'is_active' => 'Account',
        'amount' => 'Amount',
        'allocated_amount' => 'Allocated',
        'total_approved_budget' => 'Approved budget',
        'budget' => 'Budget',
        'spent' => 'Spent',
        'remaining' => 'Remaining',
        'funding_agency_amount' => 'Funder share',
        'implementing_agency_counterpart' => 'MSU–Main Campus counterpart',
        'basic_rate' => 'Basic rate',
        'gross_pay' => 'Gross pay',
        'total_deductions' => 'Deductions',
        'net_pay' => 'Net pay',
        'percentage' => 'Share of budget',
        'reviewed_by' => 'Reviewed by',
        'verified_by' => 'Verified by',
        'recorded_by' => 'Recorded by',
        'requested_by' => 'Requested by',
        'computed_by' => 'Computed by',
        'personnel_id' => 'Personnel',
        'user_id' => 'Person',
        'project_id' => 'Project',
        'lib_category_id' => 'Line item',
        'project_tranche_id' => 'Release',
        'funding_agency_id' => 'Funder',
        'review_notes' => 'Remark',
        'remarks' => 'Remarks',
        'notes' => 'Notes',
        'title' => 'Title',
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'employee_id' => 'Employee ID',
        'contact_number' => 'Contact number',
        'college' => 'College',
        'department' => 'Department',
        'position' => 'Position',
        'code' => 'Code',
        'description' => 'Description',
        'justification' => 'Justification',
        'reference_no' => 'Reference',
        'expense_date' => 'Expense date',
        'released_at' => 'Released on',
        'planned_release_date' => 'Planned release',
        'due_date' => 'Due date',
        'submitted_at' => 'Submitted on',
        'start_date' => 'Start date',
        'end_date' => 'End date',
        'line_item_name' => 'Line item',
        'line_item_code' => 'Line item code',
        'category_type' => 'Category',
        'type' => 'Type',
        'sequence' => 'Release number',
        'pay_month' => 'Month',
        'pay_year' => 'Year',
        'funder_reference' => 'Funder reference',
        'workflow_status' => 'Stage',
        'pl_reviewed_by' => 'Approved by (Project Leader)',
        'payee' => 'Payee',
        'document_type' => 'Document type',
        'file_name' => 'File',
        'uploaded_by' => 'Uploaded by',
        'quantity' => 'Quantity',
        'cancel_reason' => 'Cancel reason',
        'submitted_by' => 'Submitted by',
        'cancelled_by' => 'Cancelled by',
        'fulfilled_by' => 'Fulfilled by',
        'estimated_amount' => 'Estimated amount',
        'supplier' => 'Supplier / payee',
        'monthly_rate' => 'Monthly rate',
        'allowance' => 'Allowance',
    ];

    private const MONEY = [
        'amount', 'allocated_amount', 'total_approved_budget', 'funding_agency_amount', 'budget', 'spent', 'remaining',
        'implementing_agency_counterpart', 'basic_rate', 'gross_pay', 'total_deductions', 'net_pay',
        'estimated_amount', 'monthly_rate', 'allowance',
    ];

    private const USER_KEYS = [
        'user_id', 'reviewed_by', 'verified_by', 'recorded_by', 'requested_by', 'computed_by',
        'personnel_id', 'registration_reviewed_by', 'pl_reviewed_by', 'uploaded_by', 'submitted_by', 'cancelled_by', 'fulfilled_by',
    ];

    /** Technical fields that repeat the row or carry no meaning for a reader. */
    private const HIDDEN = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'reviewed_at', 'verified_at',
        'registration_rejected_at', 'registration_reviewed_by', 'email_verified_at', 'last_login_at',
        'assigned_at', 'payload', 'role_label', 'project', 'ip_address', 'attachment_path',
        'document_path', 'liquidation_id', 'registration_rejection_reason', 'reason',
        'return_reason', 'pl_reviewed_at', 'expense_id', 'documentable_type', 'documentable_id',
        'file_path', 'disk', 'mime', 'size', 'uploaded_at', 'record', 'record_id',
        'fulfilled_at', 'cancelled_at',
    ];

    private array $users = [];
    private array $roles = [];
    private array $projects = [];
    private array $lineItems = [];
    private array $tranches = [];
    /** The release the entry being presented is about, if any (see releaseOf). */
    private ?ProjectTranche $entryRelease = null;
    /** @var array<string, array<int, \Illuminate\Database\Eloquent\Model>> */
    private array $records = [];
    /** @var array<string, array<int, array<string, mixed>>> values from "added" / "deleted" entries */
    private array $snapshots = [];

    /**
     * @param Collection<int, AuditTrail> $entries
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $entries): array
    {
        $this->preload($entries);

        return $entries->map(fn (AuditTrail $entry) => $this->row($entry))->values()->all();
    }

    public static function actionOptions(Collection $codes): array
    {
        return $codes
            ->filter()
            ->unique()
            ->map(fn (string $code) => ['value' => $code, 'label' => self::ACTION_LABELS[$code] ?? self::humanize($code)])
            ->sortBy('label')
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------ load

    private function preload(Collection $entries): void
    {
        $ids = fn (string $type) => $entries->where('model_type', $type)->pluck('model_id')->filter()->unique()->all();

        $this->loadSnapshots($entries);
        $snapshotValues = collect($this->snapshots)->flatMap(fn (array $byId) => array_values($byId));

        $values = fn (string $key) => $entries
            ->flatMap(fn (AuditTrail $e) => [$e->old_values[$key] ?? null, $e->new_values[$key] ?? null])
            ->merge($snapshotValues->map(fn (array $v) => $v[$key] ?? null))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->all();

        $approvals = ApprovalRequest::query()->whereIn('id', $ids('ApprovalRequest'))->get()->keyBy('id');
        $this->records = [
            'ApprovalRequest' => $approvals->all(),
            // Deleted (soft-deleted) expenses still name the record.
            'Expense' => Expense::withTrashed()->whereIn('id', $ids('Expense'))->get()->keyBy('id')->all(),
            'Document' => Document::query()->whereIn('id', $ids('Document'))->get()->keyBy('id')->all(),
            'PurchaseRequest' => PurchaseRequest::withTrashed()->whereIn('id', $ids('PurchaseRequest'))->get()->keyBy('id')->all(),
            'PayrollBatch' => PayrollBatch::query()->whereIn('id', $ids('PayrollBatch'))->get()->keyBy('id')->all(),
            'Payroll' => Payroll::query()->whereIn('id', $ids('Payroll'))->get()->keyBy('id')->all(),
            // Also the approvals' liquidations: their titles name the request.
            'Liquidation' => Liquidation::query()
                ->whereIn('id', array_unique(array_merge($ids('Liquidation'), $approvals->pluck('liquidation_id')->filter()->all())))
                ->get()->keyBy('id')->all(),
            'ProjectUser' => ProjectUser::query()->whereIn('id', $ids('ProjectUser'))->get()->keyBy('id')->all(),
        ];

        $budgetIds = $entries
            ->where('action', 'budget_changed')
            ->flatMap(function (AuditTrail $e) {
                $payload = $this->payloadOf($e);

                return [$payload['from_lib_category_id'] ?? null, $payload['to_lib_category_id'] ?? null];
            })
            ->filter()
            ->all();

        $lineItemIds = array_merge(
            $ids('LibCategory'),
            $values('lib_category_id'),
            $budgetIds,
            collect($this->records['Expense'])->pluck('lib_category_id')->all(),
            collect($this->records['PurchaseRequest'])->pluck('lib_category_id')->all(),
            collect($this->records['Payroll'])->pluck('lib_category_id')->all()
        );
        $this->lineItems = LibCategory::query()->whereIn('id', array_unique(array_filter($lineItemIds)))->get()->keyBy('id')->all();

        $trancheIds = array_merge(
            $ids('ProjectTranche'),
            $values('project_tranche_id'),
            $approvals->pluck('project_tranche_id')->filter()->all(),
            collect($this->records['Liquidation'])->pluck('project_tranche_id')->filter()->all()
        );
        $this->tranches = ProjectTranche::query()->whereIn('id', array_unique($trancheIds))->get()->keyBy('id')->all();

        // Titles read the stored release number (displayTitle) without a query per row.
        foreach ($this->records['Liquidation'] as $liquidation) {
            $liquidation->setRelation('tranche', $this->tranches[(int) $liquidation->project_tranche_id] ?? null);
        }
        foreach ($approvals as $approval) {
            $approval->setRelation('tranche', $this->tranches[(int) $approval->project_tranche_id] ?? null);
            $approval->setRelation('liquidation', $this->records['Liquidation'][(int) $approval->liquidation_id] ?? null);
        }

        $userIds = array_merge(
            $entries->pluck('user_id')->filter()->all(),
            $ids('User'),
            collect(self::USER_KEYS)->flatMap(fn ($key) => $values($key))->all(),
            collect($this->records['Payroll'])->pluck('personnel_id')->all(),
            collect($this->records['ProjectUser'])->pluck('user_id')->all()
        );
        $this->users = User::query()->with('primaryRole')->whereIn('id', array_unique(array_filter($userIds)))->get()->keyBy('id')->all();

        $this->roles = Role::query()->get()->keyBy('id')->all();

        $projectIds = array_merge(
            $entries->pluck('project_id')->filter()->all(),
            $ids('Project'),
            $values('project_id')
        );
        $this->projects = Project::withTrashed()->whereIn('id', array_unique(array_filter($projectIds)))->get()->keyBy('id')->all();
    }

    /**
     * One query: the "added" and "deleted" entries of the records on this
     * page. Their stored values name a record that no longer exists (an
     * edit of an expense that was later deleted still shows its reference).
     */
    private function loadSnapshots(Collection $entries): void
    {
        $byType = $entries
            ->filter(fn (AuditTrail $e) => $e->model_type && $e->model_id)
            ->groupBy('model_type')
            ->map(fn (Collection $group) => $group->pluck('model_id')->unique()->values()->all());

        $this->snapshots = [];
        if ($byType->isEmpty()) {
            return;
        }

        AuditTrail::query()
            ->whereIn('action', ['created', 'deleted'])
            ->where(function ($query) use ($byType) {
                foreach ($byType as $type => $ids) {
                    $query->orWhere(fn ($q) => $q->where('model_type', $type)->whereIn('model_id', $ids));
                }
            })
            ->orderBy('id')
            ->get(['model_type', 'model_id', 'action', 'old_values', 'new_values'])
            ->each(function (AuditTrail $entry) {
                $values = AuditLogger::withoutSecrets($entry->action === 'deleted' ? ($entry->old_values ?? []) : ($entry->new_values ?? []));
                $this->snapshots[$entry->model_type][(int) $entry->model_id] = array_merge(
                    $this->snapshots[$entry->model_type][(int) $entry->model_id] ?? [],
                    $values
                );
            });
    }

    // ------------------------------------------------------------------- row

    private function row(AuditTrail $entry): array
    {
        $type = (string) $entry->model_type;
        $old = AuditLogger::withoutSecrets($entry->old_values ?? []);
        $new = AuditLogger::withoutSecrets($entry->new_values ?? []);
        $reason = $this->reasonOf($entry, $old, $new);
        $project = $entry->project_id ? ($this->projects[$entry->project_id] ?? null) : null;
        $actor = $entry->user_id ? ($this->users[$entry->user_id] ?? null) : null;

        return [
            'id' => $entry->id,
            'created_at' => $entry->created_at?->toIso8601String(),
            'action' => [
                'code' => $entry->action,
                'label' => $this->actionLabel($entry->action, $type),
                'tone' => $this->tone($entry->action),
            ],
            'actor' => $actor ? [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->primaryRole?->name,
            ] : null,
            'record' => $this->record($entry, $type, $old, $new),
            'project' => $project ? ['id' => $project->id, 'title' => $project->title] : null,
            'changes' => $this->changes($entry, $old, $new, $reason),
            'reason' => $reason,
        ];
    }

    private function actionLabel(string $action, string $type): string
    {
        $noun = self::TYPE_LABELS[$type] ?? self::humanize($type);

        return match ($action) {
            'approved' => $type === 'User' ? 'Account approved' : ($type === 'ApprovalRequest' ? 'Request approved' : "{$noun} approved"),
            'rejected' => $type === 'User' ? 'Registration rejected' : ($type === 'ApprovalRequest' ? 'Request rejected' : "{$noun} rejected"),
            'created' => $type === 'ProjectUser' ? 'Assigned to project' : ($type === 'Document' ? 'Document uploaded' : "{$noun} added"),
            'updated' => $type === 'ProjectUser' ? 'Project role changed' : "{$noun} edited",
            'deleted' => $type === 'ProjectUser' ? 'Removed from project' : "{$noun} deleted",
            'verified' => "{$noun} verified",
            'submitted' => "{$noun} submitted",
            'endorsed' => "{$noun} approved by Project Leader",
            'returned' => $type === 'ApprovalRequest' ? 'Request returned' : "{$noun} returned",
            'resubmitted' => $type === 'ApprovalRequest' ? 'Request resubmitted' : "{$noun} resubmitted",
            'withdrawn' => $type === 'ApprovalRequest' ? 'Request withdrawn' : "{$noun} withdrawn",
            'computed' => "{$noun} computed",
            'fulfilled' => "{$noun} fulfilled",
            'cancelled' => "{$noun} cancelled",
            'reopened' => "{$noun} reopened",
            'requested' => $type === 'ApprovalRequest' ? 'Request submitted' : "{$noun} submitted",
            default => self::ACTION_LABELS[$action] ?? self::humanize($action),
        };
    }

    private function tone(string $action): string
    {
        if (in_array($action, ['approved', 'endorsed', 'verified', 'fulfilled'], true)) {
            return 'success';
        }

        return in_array($action, ['rejected', 'returned', 'withdrawn', 'cancelled', 'deleted', 'assignment_removed'], true) ? 'danger' : 'neutral';
    }

    // ---------------------------------------------------------------- record

    private function record(AuditTrail $entry, string $type, array $old, array $new): array
    {
        $id = (int) $entry->model_id;
        $snapshot = $this->snapshots[$type][$id] ?? [];
        $pick = fn (string $key) => $new[$key] ?? $old[$key] ?? $snapshot[$key] ?? null;
        $noun = self::TYPE_LABELS[$type] ?? self::humanize($type);
        $projectLink = $entry->project_id ? ['kind' => 'project', 'project_id' => (int) $entry->project_id] : null;

        // Assignment entries are about a person on a project.
        if (str_starts_with($entry->action, 'assignment_') || str_starts_with($entry->action, 'demo_assignment')) {
            $personId = $type === 'User' ? $id : (int) ($pick('user_id') ?? 0);
            $person = $this->users[$personId] ?? null;
            $role = $pick('role_label') ?? $this->roleName($pick('role_id'));

            return [
                'label' => $person?->name ?? 'Former user',
                'type' => 'Project assignment' . ($role ? ' · ' . $role : ''),
                'link' => $person ? ['kind' => 'user', 'user_id' => $person->id] : $projectLink,
            ];
        }

        switch ($type) {
            case 'User':
                $user = $this->users[$id] ?? null;

                return [
                    'label' => $user?->name ?? $pick('name') ?? $pick('email') ?? 'Deleted user account',
                    'type' => 'User account' . ($user?->primaryRole ? ' · ' . $user->primaryRole->name : ''),
                    'link' => ['kind' => 'user', 'user_id' => $id],
                ];

            case 'ApprovalRequest':
                $approval = $this->records['ApprovalRequest'][$id] ?? null;
                $kind = $approval?->type ?? $pick('type');
                $status = $approval?->displayStatus() ?? $pick('status') ?? 'pending';
                $status = $status === 'rejected' ? 'returned' : $status;

                return [
                    'label' => $approval?->displayTitle() ?? ReleaseWording::apply($pick('title'), $this->releaseOf($entry, $type, $old, $new)) ?? 'Deleted approval request',
                    'type' => 'Approval request' . (isset(self::APPROVAL_TYPES[$kind]) ? ' · ' . self::APPROVAL_TYPES[$kind] : ''),
                    'link' => [
                        'kind' => 'approval',
                        'tab' => in_array($status, ['approved', 'returned', 'withdrawn'], true) ? $status : 'pending',
                        'project_id' => $entry->project_id ? (int) $entry->project_id : null,
                    ],
                ];

            case 'Expense':
                $expense = $this->records['Expense'][$id] ?? null;
                $reference = $expense?->reference_no ?? $pick('reference_no');
                $description = $expense?->description ?? $pick('description');
                $lineItem = $this->lineItems[$expense?->lib_category_id ?? $pick('lib_category_id')] ?? null;
                $label = trim(implode(' · ', array_filter([$reference, $description])));

                return [
                    'label' => $label !== '' ? $label : 'Deleted expense',
                    'type' => 'Expense' . ($lineItem ? ' · ' . $lineItem->line_item_name : ''),
                    'link' => $projectLink,
                ];

            case 'PayrollBatch':
                $batch = $this->records['PayrollBatch'][$id] ?? null;

                return [
                    'label' => $batch ? $batch->monthLabel() . ' payroll' : 'Payroll',
                    'type' => 'Payroll' . ($batch ? ' · ' . self::humanize(PayrollService::LABELS[$batch->workflow_status] ?? $batch->workflow_status) : ''),
                    'link' => $projectLink,
                ];

            case 'PurchaseRequest':
                $request = $this->records['PurchaseRequest'][$id] ?? null;
                $lineItem = $this->lineItems[$request?->lib_category_id ?? $pick('lib_category_id')] ?? null;

                return [
                    'label' => $request?->description ?? $pick('description') ?? 'Deleted purchase request',
                    'type' => 'Purchase request' . ($lineItem ? ' · ' . $lineItem->line_item_name : ''),
                    'link' => $projectLink,
                ];

            case 'Document':
                $document = $this->records['Document'][$id] ?? null;
                $kind = $document?->document_type ?? $pick('document_type');

                return [
                    'label' => $document?->file_name ?? $pick('file_name') ?? 'Deleted document',
                    'type' => 'Document' . ($kind ? ' · ' . self::humanize($kind) : ''),
                    'link' => $projectLink,
                ];

            case 'LibCategory':
                $item = $this->lineItems[$id] ?? null;
                $category = $item?->category_type ?? $pick('category_type');

                return [
                    'label' => $item?->line_item_name ?? $pick('line_item_name') ?? 'Deleted line item',
                    'type' => 'Line item' . ($category ? ' · ' . $category : ''),
                    'link' => $projectLink,
                ];

            case 'ProjectTranche':
                $tranche = $this->tranches[$id] ?? null;
                $status = $tranche?->status ?? $pick('status');

                return [
                    // "Release 2" from the stored number; a removed release keeps
                    // the number its last entry recorded.
                    'label' => $tranche?->releaseLabel()
                        ?? ($pick('release_number') ? ReleaseWording::label((int) $pick('release_number')) : null)
                        ?? ReleaseWording::apply($pick('name'))
                        ?? 'Deleted release',
                    'type' => 'Release' . ($status ? ' · ' . self::humanize($status) : ''),
                    'link' => $projectLink,
                ];

            case 'Payroll':
                $payroll = $this->records['Payroll'][$id] ?? null;
                $person = $this->users[$payroll?->personnel_id ?? $pick('personnel_id')] ?? null;
                $month = $payroll?->pay_month ?? $pick('pay_month');
                $year = $payroll?->pay_year ?? $pick('pay_year');
                $period = ($month && $year) ? Carbon::create((int) $year, (int) $month, 1)->format('F Y') : null;
                $label = trim(implode(' · ', array_filter([$person?->name, $period])));

                return [
                    'label' => $label !== '' ? $label : 'Deleted payroll entry',
                    'type' => 'Payroll' . (($payroll?->status ?? $pick('status')) ? ' · ' . self::humanize($payroll?->status ?? $pick('status')) : ''),
                    'link' => $projectLink,
                ];

            case 'Project':
                $project = $this->projects[$id] ?? null;

                return [
                    'label' => $project?->title ?? $pick('title') ?? 'Deleted project',
                    'type' => 'Project' . (($project?->code ?? $pick('code')) ? ' · ' . ($project?->code ?? $pick('code')) : ''),
                    'link' => ['kind' => 'project', 'project_id' => $id],
                ];

            case 'Liquidation':
                $liquidation = $this->records['Liquidation'][$id] ?? null;
                $status = $liquidation?->status ?? $pick('status');

                return [
                    'label' => ReleaseWording::apply($liquidation?->title ?? $pick('title'), $this->releaseOf($entry, $type, $old, $new)) ?? 'Deleted liquidation',
                    'type' => 'Liquidation' . ($status ? ' · ' . self::humanize($status) : ''),
                    'link' => $projectLink,
                ];

            case 'ProjectUser':
                $assignment = $this->records['ProjectUser'][$id] ?? null;
                $person = $this->users[$assignment?->user_id ?? $pick('user_id')] ?? null;
                $role = $this->roleName($assignment?->role_id ?? $pick('role_id'));

                return [
                    'label' => $person?->name ?? 'Former user',
                    'type' => 'Project assignment' . ($role ? ' · ' . $role : ''),
                    'link' => $person ? ['kind' => 'user', 'user_id' => $person->id] : $projectLink,
                ];
        }

        return [
            'label' => ReleaseWording::apply($pick('title') ?? $pick('name'), $this->releaseOf($entry, $type, $old, $new)) ?? $noun,
            'type' => $noun,
            'link' => $projectLink,
        ];
    }

    // --------------------------------------------------------------- changes

    /**
     * The release an entry is about: the release itself, the one its values
     * name, or the one its approval request or liquidation is linked to.
     */
    private function releaseOf(AuditTrail $entry, string $type, array $old, array $new): ?ProjectTranche
    {
        $id = (int) $entry->model_id;
        if ($type === 'ProjectTranche') {
            return $this->tranches[$id] ?? null;
        }
        $trancheId = $new['project_tranche_id'] ?? $old['project_tranche_id']
            ?? $this->snapshots[$type][$id]['project_tranche_id'] ?? null;
        if ($trancheId === null && in_array($type, ['ApprovalRequest', 'Liquidation'], true)) {
            $record = $this->records[$type][$id] ?? null;
            $trancheId = $record?->project_tranche_id
                ?? ($type === 'ApprovalRequest' ? $record?->liquidation?->project_tranche_id : null);
        }

        return is_numeric($trancheId) ? ($this->tranches[(int) $trancheId] ?? null) : null;
    }

    private function reasonOf(AuditTrail $entry, array $old, array $new): ?string
    {
        $reason = $entry->reason ?? $new['reason'] ?? $new['registration_rejection_reason'] ?? null;
        if ($reason === null && $entry->action === 'rejected') {
            $reason = $new['review_notes'] ?? $new['remarks'] ?? null;
        }
        $reason = is_string($reason) ? trim($reason) : null;

        return $reason !== '' ? $reason : null;
    }

    /**
     * @return array<int, array{label: string, old: ?string, new: ?string}>
     */
    private function changes(AuditTrail $entry, array $old, array $new, ?string $reason): array
    {
        if ($entry->action === 'budget_changed') {
            return $this->budgetLines($entry);
        }

        $type = (string) $entry->model_type;
        $mode = match (true) {
            $entry->action === 'updated' => 'diff',
            $entry->action === 'created' || ($old === [] && $new !== []) => 'new',
            $entry->action === 'deleted' || ($new === [] && $old !== []) => 'old',
            default => 'diff',
        };

        $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
        $lines = [];
        // Old titles name a release by an old number; show its stored one.
        $this->entryRelease = $this->releaseOf($entry, $type, $old, $new);

        foreach ($keys as $key) {
            if (! is_string($key) || $this->isHidden($key, $entry, $type)) {
                continue;
            }

            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            // Nested values are loaded relations (e.g. an approval's
            // liquidation), not fields of this record.
            if (is_array($before) || is_array($after)) {
                continue;
            }

            // A remark that is the reason is shown once, as the reason.
            if (in_array($key, ['review_notes', 'remarks'], true) && $reason !== null && trim((string) $after) === $reason) {
                continue;
            }

            if ($mode === 'diff' && $this->sameValue($before, $after)) {
                continue;
            }

            $oldText = $mode === 'new' ? null : $this->format($key, $before, $old);
            $newText = $mode === 'old' ? null : $this->format($key, $after, $new);

            // On an edit, an empty side reads as "—" (e.g. "Position: — -> Lead").
            if ($mode === 'diff') {
                $oldText ??= '—';
                $newText ??= '—';
            }

            if ($oldText === null && $newText === null) {
                continue;
            }

            $lines[] = ['label' => $this->fieldLabel($key), 'old' => $oldText, 'new' => $newText];
        }

        return $lines;
    }

    private function isHidden(string $key, AuditTrail $entry, string $type): bool
    {
        if (in_array($key, self::HIDDEN, true)) {
            return true;
        }

        // Ids already shown in the row (its project, its own record).
        if ($key === 'project_id' && $entry->action !== 'updated') {
            return true;
        }
        if ($key === 'user_id' && $type === 'User') {
            return true;
        }
        // A release is shown as "Release N" from its stored number; its
        // stored name ("Second tranche") and add order would contradict that.
        if ($type === 'ProjectTranche' && in_array($key, ['name', 'sequence', 'release_number'], true)) {
            return true;
        }

        return false;
    }

    private function budgetLines(AuditTrail $entry): array
    {
        // Older entries were also written for rejected realignments, where
        // no money moved; those show only their reason.
        $status = $entry->new_values['status'] ?? null;
        if ($status !== null && $status !== 'approved') {
            return [];
        }

        $payload = $this->payloadOf($entry);
        $amount = (float) ($payload['amount'] ?? 0);
        $from = $this->lineItems[$payload['from_lib_category_id'] ?? 0] ?? null;
        $to = $this->lineItems[$payload['to_lib_category_id'] ?? 0] ?? null;

        if ($amount <= 0 || (! $from && ! $to)) {
            return [];
        }

        return array_values(array_filter([
            $from ? ['label' => $from->line_item_name, 'old' => null, 'new' => '−' . self::money($amount)] : null,
            $to ? ['label' => $to->line_item_name, 'old' => null, 'new' => '+' . self::money($amount)] : null,
        ]));
    }

    private function payloadOf(AuditTrail $entry): array
    {
        $payload = $entry->new_values['payload'] ?? $entry->old_values['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function format(string $key, $value, array $values): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '—';
        }

        if ($key === 'role' || $key === 'role_id') {
            return $values['role_label'] ?? $this->roleName($value) ?? self::humanize((string) $value);
        }
        if ($key === 'is_active') {
            return $this->truthy($value) ? 'Active' : 'Inactive';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (in_array($key, self::MONEY, true) && is_numeric($value)) {
            return self::money((float) $value);
        }
        if ($key === 'percentage' && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '%';
        }
        if (in_array($key, self::USER_KEYS, true)) {
            return ($this->users[(int) $value] ?? null)?->name ?? 'Former user';
        }
        if ($key === 'project_id') {
            return ($this->projects[(int) $value] ?? null)?->title ?? 'Deleted project';
        }
        if ($key === 'lib_category_id') {
            return ($this->lineItems[(int) $value] ?? null)?->line_item_name ?? 'Deleted line item';
        }
        if ($key === 'project_tranche_id') {
            return ($this->tranches[(int) $value] ?? null)?->releaseLabel() ?? 'Deleted release';
        }
        if ($key === 'status' || $key === 'category_type') {
            return self::humanize((string) $value);
        }
        if ($key === 'type' && isset(self::APPROVAL_TYPES[$value])) {
            return self::APPROVAL_TYPES[$value];
        }
        if ($key === 'pay_month' && is_numeric($value) && $value >= 1 && $value <= 12) {
            return Carbon::create(2000, (int) $value, 1)->format('F');
        }
        if (is_string($value) && $this->looksLikeDate($key, $value)) {
            return $this->formatDate($value, $this->isDateOnly($key));
        }
        if (is_array($value)) {
            return null;
        }
        if (in_array($key, ['title', 'name'], true)) {
            // Older titles and names read "tranche"; users read "release".
            return ReleaseWording::apply((string) $value, $this->entryRelease);
        }

        return (string) $value;
    }

    private function looksLikeDate(string $key, string $value): bool
    {
        return (str_ends_with($key, '_at') || str_ends_with($key, '_date') || in_array($key, ['date', 'released_at'], true))
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    /**
     * Columns that hold a calendar date (stored by Laravel as
     * "YYYY-MM-DD 00:00:00"). They are never shifted to another time zone.
     */
    private function isDateOnly(string $key): bool
    {
        return str_ends_with($key, '_date') || in_array($key, ['date', 'released_at', 'submitted_at'], true);
    }

    /** Date-only values stay calendar dates; timestamps are shown in Philippine time. */
    private function formatDate(string $value, bool $dateOnly = false): string
    {
        try {
            if ($dateOnly || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return Carbon::createFromFormat('Y-m-d', substr($value, 0, 10))->format('M j, Y');
            }

            return Carbon::parse($value, 'UTC')->setTimezone(self::TIMEZONE)->format('M j, Y g:i A');
        } catch (Throwable) {
            return $value;
        }
    }

    private function roleName($id): ?string
    {
        if (! is_numeric($id)) {
            return null;
        }

        return ($this->roles[(int) $id] ?? null)?->name ?? null;
    }

    private function truthy($value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    private function sameValue($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private function fieldLabel(string $key): string
    {
        return self::FIELD_LABELS[$key] ?? self::humanize($key);
    }

    public static function money(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    public static function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', trim($value)));
    }
}
