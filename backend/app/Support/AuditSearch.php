<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Document;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Liquidation;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Audit log search box ("Search user, record or action"), run on the
 * server across all entries. One case-insensitive term matches:
 * - the user's name ("System" for automated entries),
 * - the action, as the page words it ("Expense verified") or its code,
 * - the record type ("Line item") and the record's own name or title,
 * - the project's code or title.
 * It only narrows the query it is given, so the caller's role scope and
 * other filters still apply. Plain LIKE (no MySQL-only SQL) so SQLite tests
 * run the same query.
 */
class AuditSearch
{
    /** Columns that name a record, per audited model (read from its table, soft-deleted rows included). */
    private const RECORD_NAMES = [
        'User' => [User::class, ['name', 'email']],
        'Project' => [Project::class, ['title', 'code']],
        'LibCategory' => [LibCategory::class, ['line_item_name']],
        'Expense' => [Expense::class, ['description', 'reference_no', 'payee']],
        'ApprovalRequest' => [ApprovalRequest::class, ['title']],
        'PurchaseRequest' => [PurchaseRequest::class, ['description']],
        'Document' => [Document::class, ['file_name']],
        'Liquidation' => [Liquidation::class, ['title']],
    ];

    public static function apply(Builder $query, ?string $search): Builder
    {
        $term = mb_strtolower(trim((string) $search));
        if ($term === '') {
            return $query;
        }

        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $matches = fn (string $text) => str_contains(mb_strtolower($text), $term);

        $users = self::matching((new User())->getTable(), ['name'], $like);
        $projects = self::matching((new Project())->getTable(), ['title', 'code'], $like);

        // Labels are worded per (action, record type), so read the pairs in use.
        $pairs = AuditTrail::query()->select('action', 'model_type')->distinct()->get();
        $actionPairs = [];
        $types = [];
        foreach ($pairs as $pair) {
            $action = (string) $pair->action;
            $type = (string) $pair->model_type;
            if ($matches(AuditEntryPresenter::actionLabelFor($action, $type))
                || $matches(AuditEntryPresenter::ACTION_LABELS[$action] ?? '')
                || $matches($action)) {
                $actionPairs[$action][] = $type;
            }
            if ($type !== '' && ($matches(AuditEntryPresenter::typeLabel($type)) || $matches($type))) {
                $types[$type] = true;
            }
        }

        return $query->where(function (Builder $where) use ($term, $like, $users, $projects, $actionPairs, $types) {
            $where->whereIn('user_id', $users)
                ->orWhereIn('project_id', $projects);

            if (str_contains('system', $term)) {
                $where->orWhereNull('user_id');
            }

            foreach ($actionPairs as $action => $pairTypes) {
                $where->orWhere(fn (Builder $q) => $q->where('action', $action)->whereIn('model_type', $pairTypes));
            }

            if ($types !== []) {
                $where->orWhereIn('model_type', array_keys($types));
            }

            foreach (self::RECORD_NAMES as $type => [$model, $columns]) {
                $ids = self::matching((new $model())->getTable(), $columns, $like);
                $where->orWhere(fn (Builder $q) => $q->where('model_type', $type)->whereIn('model_id', $ids));
            }

            // Records named after a person: assignments and payroll rows.
            $where->orWhere(fn (Builder $q) => $q->where('model_type', 'ProjectUser')->whereIn(
                'model_id',
                DB::table((new ProjectUser())->getTable())->select('id')->whereIn('user_id', $users)
            ));
            $where->orWhere(fn (Builder $q) => $q->where('model_type', 'Payroll')->whereIn(
                'model_id',
                DB::table((new Payroll())->getTable())->select('id')->whereIn('personnel_id', $users)
            ));
        });
    }

    /** Ids of rows in $table where any of $columns contains the term (case-insensitive). */
    private static function matching(string $table, array $columns, string $like): \Illuminate\Database\Query\Builder
    {
        return DB::table($table)->select('id')->where(function ($q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhereRaw('LOWER(' . $column . ") LIKE ? ESCAPE '!'", [$like]);
            }
        });
    }
}
