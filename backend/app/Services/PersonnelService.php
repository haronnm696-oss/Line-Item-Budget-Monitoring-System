<?php

namespace App\Services;

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectPersonnel;
use App\Models\ProjectUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Project personnel (the team appointed by Special Order, paid PS honoraria
 * through payroll). The one place pay is kept; payroll reads recordsByPerson().
 *
 * Access:
 *   edit     Program Leader
 *   view     Project Leader, Finance/Admin of the project (read-only)
 *   limited  Research Assistant of the project: name, position and period only
 *   null     everyone else, including Researchers (403)
 *
 * The bank account number is encrypted at rest and only ever leaves as a
 * mask ("****9170"); a full number is never in these responses or the audit
 * log. Each create, edit, removal and Special Order upload or removal writes
 * exactly one audit entry; a save that changes nothing writes none.
 */
class PersonnelService
{
    public const EDIT = 'edit';
    public const VIEW = 'view';
    public const LIMITED = 'limited';

    public function access(Project $project, User $user): ?string
    {
        if ($user->isProgramLeader()) {
            return self::EDIT;
        }
        $roles = $user->rolesOn($project);
        if (array_intersect($roles, ['project-leader', 'finance-admin']) !== []) {
            return self::VIEW;
        }

        return in_array('research-assistant', $roles, true) ? self::LIMITED : null;
    }

    /** The Personnel section: records as this user may see them. */
    public function listing(Project $project, User $user): array
    {
        $access = $this->access($project, $user);
        abort_if($access === null, 403, 'Only the Program Leader, the Project Leader, Finance/Admin staff and Research Assistants of this project can see its personnel.');

        $records = $project->personnel()
            ->with(['user', 'lineItem', 'documents'])
            ->orderBy('effective_from')->orderBy('id')
            ->get();

        $data = [
            'access' => $access,
            'period' => [
                'start' => $project->start_date?->toDateString(),
                'end' => $project->end_date?->toDateString(),
            ],
            'personnel' => $records->map(fn (ProjectPersonnel $record) => $access === self::LIMITED
                ? $this->limitedRow($record)
                : $this->row($record))->values()->all(),
        ];

        if ($access === self::EDIT) {
            $data['members'] = $this->members($project);
            $data['ps_line_items'] = $project->libCategories()->where('category_type', 'PS')->orderBy('id')->get()
                ->map(fn (LibCategory $item) => ['id' => $item->id, 'name' => $item->line_item_name, 'code' => $item->line_item_code])
                ->values()->all();
        }

        return $data;
    }

    public function create(Project $project, array $data, User $actor): ProjectPersonnel
    {
        $this->requireMember($project, (int) $data['user_id']);

        return AuditLogger::transaction(function () use ($project, $data, $actor) {
            Project::whereKey($project->id)->lockForUpdate()->first();
            $this->requireNoOverlap($project, (int) $data['user_id'], $data['effective_from'], $data['effective_to']);

            $record = ProjectPersonnel::create([
                'project_id' => $project->id,
                'user_id' => (int) $data['user_id'],
                'created_by' => $actor->id,
            ] + $this->attributes($data, null));
            AuditLogger::write('created', $record, [], $this->snapshot($record), $actor);

            return $record;
        });
    }

    public function update(ProjectPersonnel $record, array $data, User $actor): ProjectPersonnel
    {
        return AuditLogger::transaction(function () use ($record, $data, $actor) {
            $locked = ProjectPersonnel::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->requireNoOverlap($locked->project, (int) $locked->user_id, $data['effective_from'], $data['effective_to'], $locked->id);

            $old = $this->snapshot($locked);
            $locked->fill($this->attributes($data, $locked));
            if (! $locked->isDirty()) {
                return $locked; // nothing changed: no audit entry
            }
            $locked->save();
            AuditLogger::write('updated', $locked, $old, $this->snapshot($locked), $actor);

            return $locked;
        });
    }

    public function remove(ProjectPersonnel $record, User $actor): void
    {
        if ($record->specialOrder()) {
            throw ValidationException::withMessages(['special_order' => ['Remove the uploaded Special Order first.']]);
        }
        AuditLogger::transaction(function () use ($record, $actor) {
            $old = $this->snapshot($record);
            $record->delete();
            AuditLogger::write('deleted', $record, $old, [], $actor);
        });
    }

    public function uploadSpecialOrder(ProjectPersonnel $record, UploadedFile $file, User $actor): Document
    {
        if ($record->specialOrder()) {
            throw ValidationException::withMessages(['file' => ['A Special Order is already uploaded. Remove it before uploading another.']]);
        }

        return AuditLogger::transaction(fn () => DocumentController::attach($record, $file, ProjectPersonnel::DOCUMENT_TYPE, $actor));
    }

    public function removeSpecialOrder(ProjectPersonnel $record, User $actor): void
    {
        $document = $record->specialOrder();
        abort_unless($document, 404, 'No Special Order is uploaded for this person.');
        AuditLogger::transaction(fn () => DocumentController::detach($document, $record, $actor));
        DocumentController::deleteFiles([$document]);
    }

    /**
     * Payroll: every personnel record of the project whose person is still
     * active and assigned to it, grouped by user id (a person may have more
     * than one Special Order over time; their periods never overlap).
     *
     * @return Collection<int, Collection<int, ProjectPersonnel>>
     */
    public function recordsByPerson(Project $project): Collection
    {
        $members = ProjectUser::query()->where('project_id', $project->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return ProjectPersonnel::query()
            ->where('project_id', $project->id)
            ->with('user')
            ->orderBy('effective_from')->orderBy('id')
            ->get()
            ->filter(fn (ProjectPersonnel $record) => $record->user && $record->user->is_active && in_array((int) $record->user_id, $members, true))
            ->groupBy(fn (ProjectPersonnel $record) => (int) $record->user_id);
    }

    // ---------------------------------------------------------------- rows

    public function row(ProjectPersonnel $record): array
    {
        $order = $record->documents->firstWhere('document_type', ProjectPersonnel::DOCUMENT_TYPE);

        return [
            'id' => $record->id,
            'user_id' => (int) $record->user_id,
            'name' => $record->user?->name,
            'position' => $record->position,
            'lib_category_id' => $record->lib_category_id !== null ? (int) $record->lib_category_id : null,
            'line_item' => $record->lineItem?->line_item_name,
            'special_order_no' => $record->special_order_no,
            'effective_from' => $record->effective_from?->toDateString(),
            'effective_to' => $record->effective_to?->toDateString(),
            'monthly_honorarium' => round((float) $record->monthly_honorarium, 2),
            'bank_account_masked' => $record->maskedBankAccount(),
            'special_order_document' => $order ? ['id' => $order->id, 'file_name' => $order->file_name] : null,
        ];
    }

    public function limitedRow(ProjectPersonnel $record): array
    {
        return [
            'id' => $record->id,
            'user_id' => (int) $record->user_id,
            'name' => $record->user?->name,
            'position' => $record->position,
            'effective_from' => $record->effective_from?->toDateString(),
            'effective_to' => $record->effective_to?->toDateString(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** Validated input -> model attributes. bank_account: absent = keep, null/"" = remove. */
    private function attributes(array $data, ?ProjectPersonnel $current): array
    {
        $attributes = [
            'position' => trim($data['position']),
            'lib_category_id' => (int) $data['lib_category_id'],
            'special_order_no' => trim($data['special_order_no']),
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'],
            'monthly_honorarium' => round((float) $data['monthly_honorarium'], 2),
        ];
        if (array_key_exists('bank_account', $data)) {
            $account = trim((string) $data['bank_account']);
            $unchanged = $current && $account !== '' && $account === (string) $current->bank_account;
            if (! $unchanged) {
                $attributes['bank_account'] = $account === '' ? null : $account;
            }
        }

        return $attributes;
    }

    /** Audited values: the bank account only as its mask. */
    private function snapshot(ProjectPersonnel $record): array
    {
        return [
            'project_id' => (int) $record->project_id,
            'user_id' => (int) $record->user_id,
            'position' => $record->position,
            'lib_category_id' => $record->lib_category_id !== null ? (int) $record->lib_category_id : null,
            'special_order_no' => $record->special_order_no,
            'effective_from' => $record->effective_from?->toDateString(),
            'effective_to' => $record->effective_to?->toDateString(),
            'monthly_honorarium' => round((float) $record->monthly_honorarium, 2),
            'bank_account_masked' => $record->maskedBankAccount(),
        ];
    }

    /** Project members (one row per person) for the "Add personnel" form. */
    private function members(Project $project): array
    {
        return ProjectUser::query()->where('project_id', $project->id)->with(['user', 'role'])->orderBy('id')->get()
            ->filter(fn (ProjectUser $member) => $member->user !== null)
            ->unique('user_id')
            ->map(fn (ProjectUser $member) => ['user_id' => (int) $member->user_id, 'name' => $member->user->name, 'role' => $member->role?->name])
            ->sortBy('name')->values()->all();
    }

    private function requireMember(Project $project, int $userId): void
    {
        if (! ProjectUser::query()->where('project_id', $project->id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Choose a member of this project.']]);
        }
    }

    private function requireNoOverlap(Project $project, int $userId, string $from, string $to, ?int $exceptId = null): void
    {
        $overlaps = ProjectPersonnel::query()
            ->where('project_id', $project->id)->where('user_id', $userId)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['effective_from' => ['This person already has a personnel record for part of this period.']]);
        }
    }
}
