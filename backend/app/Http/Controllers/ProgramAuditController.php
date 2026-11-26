<?php

namespace App\Http\Controllers;

use App\Rules\DateInRange;
use App\Models\AuditTrail;
use App\Models\Project;
use App\Models\User;
use App\Support\AuditEntryPresenter;
use App\Support\AuditSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Read-only Audit log for the Program Leader (/program-audit).
 * Entries are filtered (and searched with `q`, see AuditSearch) and paged
 * on the server, newest first, and presented
 * as readable rows by AuditEntryPresenter. There is deliberately no route
 * that edits or deletes an entry.
 */
class ProgramAuditController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:50'],
            'project' => ['nullable', 'integer'],
            'user' => ['nullable', 'integer'],
            'from' => ['bail', 'nullable', new DateInRange(), 'date_format:Y-m-d'],
            'to' => ['bail', 'nullable', new DateInRange(), 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = AuditTrail::query()
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($data['project'] ?? null, fn ($q, $project) => $q->where('project_id', $project))
            ->when($data['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            // FROM / TO are Philippine calendar days; entries are stored in UTC.
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where(
                'created_at',
                '>=',
                Carbon::createFromFormat('Y-m-d', $from, AuditEntryPresenter::TIMEZONE)->startOfDay()->utc()
            ))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where(
                'created_at',
                '<=',
                Carbon::createFromFormat('Y-m-d', $to, AuditEntryPresenter::TIMEZONE)->endOfDay()->utc()
            ));
        AuditSearch::apply($query, $data['q'] ?? null);

        $total = (clone $query)->count();
        $page = (int) ($data['page'] ?? 1);
        $entries = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        return response()->json([
            'entries' => (new AuditEntryPresenter())->present($entries),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'has_more' => $page * self::PER_PAGE < $total,
                'timezone' => AuditEntryPresenter::TIMEZONE,
            ],
            'options' => $this->options(),
        ]);
    }

    private function options(): array
    {
        $userIds = AuditTrail::query()->whereNotNull('user_id')->distinct()->pluck('user_id');

        return [
            'actions' => AuditEntryPresenter::actionOptions(AuditTrail::query()->distinct()->pluck('action')),
            'projects' => Project::withTrashed()
                ->orderBy('title')
                ->get(['id', 'title', 'code'])
                ->map(fn (Project $project) => ['id' => $project->id, 'title' => $project->title, 'code' => $project->code])
                ->values()
                ->all(),
            'users' => User::query()
                ->whereIn('id', $userIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
        ];
    }
}
