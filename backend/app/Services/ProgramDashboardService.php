<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\ProjectTranche;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramDashboardService
{
    public function __construct(private ProjectFinancialService $financials)
    {
    }

    /**
     * Build the single program-wide dashboard payload.
     *
     * @return array<string, mixed>
     */
    public function overview(?string $funder = null): array
    {
        $today = now()->startOfDay();
        $projects = $this->projects($funder);
        $rows = $projects
            ->map(fn (Project $project) => $this->projectRow($project, $today))
            ->values();

        $approvals = $this->approvalRows($projects, $today);
        $expenseCount = (int) $rows->sum('expenses_count');
        $receiptCount = (int) $rows->sum('receipts_count');
        $oldestWaiting = (int) ($approvals->max('days_waiting') ?? 0);
        $forecast = $this->forecast($projects, $today);

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'funder' => $funder,
                'project_count' => $rows->count(),
            ],
            'kpis' => [
                'portfolio_budget' => round((float) $rows->sum('budget'), 2),
                'total_disbursed' => round((float) $rows->sum('spent'), 2),
                'utilization_pct' => $this->pct((float) $rows->sum('spent'), (float) $rows->sum('budget')),
                'health' => [
                    'healthy' => $rows->where('health', 'Healthy')->count(),
                    'watch' => $rows->where('health', 'Watch')->count(),
                    'at_risk' => $rows->where('health', 'At risk')->count(),
                    'closed' => $rows->where('health', 'Closed')->count(),
                ],
                'avg_receipt_compliance' => $expenseCount > 0
                    ? round(($receiptCount / $expenseCount) * 100, 1)
                    : null,
                'pending_approvals' => $approvals->count(),
                'pending_approvals_total' => $approvals->count(),
                'oldest_waiting_days' => $oldestWaiting,
            ],
            'projects' => $rows->all(),
            // The dashboard inbox is intentionally short; the Approvals page
            // exposes the full queue.
            'approvals' => $approvals->take(3)->values()->all(),
            'forecast' => $forecast,
            'scheduled_releases' => $forecast,
            'funders' => $this->funderOptions(),
            'funding_sources' => $this->fundingSources($rows),
            'deadlines' => $this->deadlines($projects, $today),
            'exceptions' => $this->exceptions($projects, $rows),
        ];
    }

    /**
     * Approval workspace: filtered rows, tab counts, recently decided rows,
     * and the options needed by the filter bar.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function approvalList(array $filters = []): array
    {
        $today = now()->startOfDay();

        $query = ApprovalRequest::query()
            ->with(['project.fundingAgency', 'requestedBy.primaryRole', 'reviewedBy', 'tranche', 'liquidation'])
            ->when($filters['type'] ?? null, function ($query, $type) {
                if ($type === 'fund-release') {
                    $query->whereIn('type', ['disbursement', 'payroll']);
                } elseif (in_array($type, ['realignment', 'budget-change'], true)) {
                    $query->where('type', 'budget_realignment');
                } else {
                    $query->where('type', $type);
                }
            })
            ->when($filters['project'] ?? null, fn ($query, $project) => $query->where('project_id', $project))
            ->when($filters['funder'] ?? null, function ($query, $funder) {
                if (ctype_digit((string) $funder)) {
                    $query->whereHas('project', fn ($project) => $project->where('funding_agency_id', (int) $funder));
                } else {
                    $query->whereHas('project.fundingAgency', function ($agency) use ($funder) {
                        $agency->where('acronym', $funder)->orWhere('name', $funder);
                    });
                }
            });

        $all = $query->orderBy('created_at')->get();

        $counts = [
            'pending' => $all->where('status', 'pending')->count(),
            'approved' => $all->where('status', 'approved')->count(),
            'rejected' => $all->where('status', 'rejected')->count(),
        ];

        $status = $filters['status'] ?? 'pending';
        $rows = $all->where('status', $status)
            ->sortBy(fn (ApprovalRequest $approval) => $approval->created_at?->timestamp ?? 0)
            ->map(fn (ApprovalRequest $approval) => $this->approvalListRow($approval, $today))
            ->values();

        $recent = $all->whereIn('status', ['approved', 'rejected'])
            ->sortByDesc(fn (ApprovalRequest $approval) => $approval->reviewed_at?->timestamp ?? 0)
            ->take(10)
            ->map(fn (ApprovalRequest $approval) => $this->approvalListRow($approval, $today))
            ->values();

        return [
            'counts' => $counts,
            'approvals' => $rows->all(),
            'recent' => $recent->all(),
            'projects' => Project::query()
                ->orderBy('title')
                ->get(['id', 'code', 'title'])
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                ])
                ->values()
                ->all(),
            'funders' => $this->funderOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function approvalListRow(ApprovalRequest $approval, Carbon $today): array
    {
        $kind = 'other';

        if (in_array($approval->type, ['disbursement', 'payroll'], true)) {
            $kind = 'tranche';
        } elseif ($approval->type === 'budget_realignment') {
            $kind = 'realignment';
        } elseif ($approval->type === 'liquidation') {
            $kind = 'liquidation';
        }

        $created = $approval->created_at
            ? $approval->created_at->copy()->startOfDay()
            : $today->copy();

        return [
            'id' => $approval->id,
            'kind' => $kind,
            'type' => $approval->type,
            'type_label' => $approval->typeLabel(),
            'title' => $approval->type === 'liquidation'
                ? ($approval->liquidation?->title ?? $approval->title)
                : $approval->title,
            'description' => $approval->description,
            'project_id' => $approval->project_id,
            'project' => $approval->project?->title,
            'project_code' => $approval->project?->code,
            'funder' => $approval->project?->fundingAgency?->acronym
                ?? $approval->project?->fundingAgency?->name,
            'amount' => $approval->amount !== null ? (float) $approval->amount : null,
            'requested_by' => $approval->requestedBy?->name,
            'requested_by_id' => $approval->requested_by,
            'requested_by_role' => $approval->requestedBy?->primaryRole?->name,
            'days_waiting' => max(0, (int) $created->diffInDays($today)),
            'status' => $approval->status,
            'review_notes' => $approval->review_notes,
            'created_at' => $approval->created_at?->toIso8601String(),
            'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            'reviewed_by' => $approval->reviewedBy?->name,
        ];
    }

    /**
     * Apply one approval decision atomically and write an audit trail row.
     */
    public function decide(
        ApprovalRequest $approval,
        string $decision,
        ?string $remarks,
        User $actor
    ): ApprovalRequest {
        if ((int) $approval->requested_by === (int) $actor->id) {
            abort(403, 'You cannot approve or reject a request you created.');
        }

        if (! $approval->isPending()) {
            abort(409, 'This approval request has already been reviewed.');
        }

        DB::transaction(function () use ($approval, $decision, $remarks, $actor) {
            $locked = ApprovalRequest::query()
                ->with(['liquidation'])
                ->lockForUpdate()
                ->findOrFail($approval->id);

            if ((int) $locked->requested_by === (int) $actor->id) {
                abort(403, 'You cannot approve or reject a request you created.');
            }

            if (! $locked->isPending()) {
                abort(409, 'This approval request has already been reviewed.');
            }

            $oldValues = $locked->toArray();

            $locked->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $remarks,
            ]);

            $this->applyDecision($locked, $decision, $remarks, $actor);

            if (($locked->type ?? '') === 'budget_realignment') {
                AuditTrail::create([
                    'user_id' => $actor->id,
                    'action' => 'budget_changed',
                    'model_type' => 'ApprovalRequest',
                    'model_id' => $locked->id,
                    'old_values' => $oldValues,
                    'new_values' => $locked->fresh()->toArray(),
                    'reason' => $remarks,
                    'ip_address' => request()->ip(),
                    'created_at' => now(),
                ]);
            }

            AuditTrail::create([
                'user_id' => $actor->id,
                'action' => $decision,
                'model_type' => 'ApprovalRequest',
                'model_id' => $locked->id,
                'old_values' => $oldValues,
                'new_values' => $locked->fresh()->toArray(),
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        });

        return $approval->fresh()->load(['requestedBy', 'tranche', 'liquidation']);
    }

    protected function projects(?string $funder): Collection
    {
        return Project::query()
            ->with([
                'fundingAgency',
                'tranches',
                'reports',
                'liquidations',
                'libCategories',
                'projectUsers.role',
                'projectUsers.user',
                'approvalRequests' => function ($query) {
                    $query->where('status', 'pending')
                        ->with(['requestedBy', 'tranche', 'liquidation']);
                },
            ])
            ->when($funder, function ($query) use ($funder) {
                if (ctype_digit((string) $funder)) {
                    $query->where('funding_agency_id', (int) $funder);
                } else {
                    $query->whereHas('fundingAgency', function ($agency) use ($funder) {
                        $agency->where('acronym', $funder)->orWhere('name', $funder);
                    });
                }
            })
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function projectRow(Project $project, Carbon $today): array
    {
        $metrics = $this->financials->financials($project);
        $budget = $metrics['budget'];
        $spent = $metrics['spent'];
        $spentPct = $metrics['utilization_pct'];
        $time = $this->timeMetrics($project, $today);
        $gap = round($spentPct - $time['time_pct'], 1);

        $expenseCount = $metrics['expenses_count'];
        $withReceipt = $metrics['receipts_count'];
        $receiptPct = $metrics['receipt_compliance_pct'];

        $status = $this->financials->derivedStatus($project);
        $closed = $status === 'completed'
            || ($project->end_date && $project->end_date->lt($today));
        $tranche = $this->trancheMetric($project, $today, $spentPct, $closed);
        $report = $this->reportMetric($project, $today);
        $terminalFiled = $project->reports->contains(
            fn (ProjectReport $row) => $row->type === 'terminal' && $row->submitted_at !== null
        );
        [$health, $reason] = $this->health($tranche['overdue'], $spentPct, $gap, $receiptPct, $closed, $terminalFiled);

        return [
            'id' => $project->id,
            'code' => $project->code,
            'title' => $project->title,
            'funder' => $this->funderLabel($project),
            'funder_id' => $project->funding_agency_id,
            'status' => ucfirst($status),
            'budget' => round($budget, 2),
            'spent' => round($spent, 2),
            'remaining' => round($budget - $spent, 2),
            'spent_pct' => $spentPct,
            'time_pct' => $time['time_pct'],
            'gap' => $gap,
            'elapsed_days' => $time['elapsed_days'],
            'total_days' => $time['total_days'],
            'receipt_compliance_pct' => $receiptPct,
            'expenses_count' => $expenseCount,
            'receipts_count' => $withReceipt,
            'tranche_status' => $tranche['status'],
            'tranche_overdue' => $tranche['overdue'],
            'report_status' => $report['status'],
            'next_report' => $report['next'],
            'health' => $health,
            'health_reason' => $reason,
            'project_leader' => $project->projectUsers
                ->first(fn ($assignment) => $assignment->role?->slug === 'project-leader')
                ?->user?->name,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
        ];
    }

    /**
     * @return array{time_pct: float, elapsed_days: int, total_days: int}
     */
    protected function timeMetrics(Project $project, Carbon $today): array
    {
        $start = $project->start_date
            ? $project->start_date->copy()->startOfDay()
            : $today->copy();
        $end = $project->end_date
            ? $project->end_date->copy()->startOfDay()
            : $start->copy()->addYear();

        $totalDays = max(1, (int) $start->diffInDays($end));
        $elapsedDays = 0;

        if ($today->greaterThan($start)) {
            $elapsedDays = min($totalDays, (int) $start->diffInDays($today));
        }

        return [
            'time_pct' => round(min(100, ($elapsedDays / $totalDays) * 100), 1),
            'elapsed_days' => $elapsedDays,
            'total_days' => $totalDays,
        ];
    }

    /**
     * @return array{status: string, overdue: bool}
     */
    protected function trancheMetric(Project $project, Carbon $today, float $spentPct, bool $closed = false): array
    {
        if ($closed) {
            return ['status' => 'Closed', 'overdue' => false];
        }

        $tranches = $project->tranches->sortBy('sequence')->values();
        $last = $tranches->last();
        $earlierReleased = $last
            ? $tranches->where('sequence', '<', $last->sequence)
                ->every(fn (ProjectTranche $tranche) => $tranche->status === 'released')
            : false;

        // A closeout tranche is expected to stay pending while the project is
        // nearly fully spent. Treat it as "Final pending" rather than overdue
        // so the health reason matches the real risk (budget depletion).
        if ($last && $last->status !== 'released' && $earlierReleased && $spentPct >= 95) {
            return ['status' => 'Final pending', 'overdue' => false];
        }

        $overdue = $tranches->contains(function (ProjectTranche $tranche) use ($today) {
            return $tranche->status !== 'released'
                && $tranche->planned_release_date
                && $tranche->planned_release_date->lt($today);
        });

        if ($overdue) {
            return ['status' => 'Overdue', 'overdue' => true];
        }

        return ['status' => 'On track', 'overdue' => false];
    }

    /**
     * @return array{status: string, next: array<string, mixed>|null}
     */
    protected function reportMetric(Project $project, Carbon $today): array
    {
        $open = $project->reports
            ->filter(fn (ProjectReport $report) => $report->submitted_at === null)
            ->sortBy(fn (ProjectReport $report) => $report->due_date?->timestamp ?? PHP_INT_MAX)
            ->values();

        $late = $open->first(fn (ProjectReport $report) => $report->due_date && $report->due_date->lt($today));
        $dueSoon = $open->first(function (ProjectReport $report) use ($today) {
            return $report->due_date
                && $report->due_date->greaterThanOrEqualTo($today)
                && $report->due_date->lessThanOrEqualTo($today->copy()->addDays(14));
        });

        $status = $late ? 'Late' : ($dueSoon ? 'Due soon' : 'On time');
        $next = $open->first();

        return [
            'status' => $status,
            'next' => $next ? [
                'id' => $next->id,
                'title' => $next->title,
                'type' => $next->type,
                'due_date' => $next->due_date?->toDateString(),
            ] : null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function health(
        bool $overdue,
        float $spentPct,
        float $gap,
        ?float $receiptPct,
        bool $closed,
        bool $terminalFiled = false
    ): array {
        // Closed projects are never scored for under-spending. They keep a
        // neutral status instead of a health risk.
        if ($closed) {
            return ['Closed', $terminalFiled ? 'Terminal report filed' : 'Project completed'];
        }

        if ($overdue) {
            return ['At risk', 'Tranche release overdue'];
        }

        if ($spentPct >= 95) {
            return ['At risk', 'Budget nearly depleted'];
        }

        if ($gap <= -40) {
            return ['At risk', 'Under-spending vs timeline by ' . abs($gap) . ' pts'];
        }

        if ($gap <= -20) {
            return ['Watch', 'Under-spending vs timeline by ' . abs($gap) . ' pts'];
        }

        if ($receiptPct !== null && $receiptPct < 80) {
            return ['Watch', 'Receipt compliance at ' . $receiptPct . '%'];
        }

        return ['Healthy', 'On track'];
    }

    protected function approvalRows(Collection $projects, Carbon $today): Collection
    {
        $projectMap = $projects->keyBy('id');

        return $projects
            ->flatMap->approvalRequests
            ->sortBy(fn (ApprovalRequest $approval) => $approval->created_at?->timestamp ?? 0)
            ->map(function (ApprovalRequest $approval) use ($projectMap, $today) {
                $project = $projectMap->get($approval->project_id);
                $kind = 'other';

                if (in_array($approval->type, ['disbursement', 'payroll'], true)) {
                    $kind = 'tranche';
                } elseif ($approval->type === 'budget_realignment') {
                    $kind = 'realignment';
                } elseif ($approval->type === 'liquidation') {
                    $kind = 'liquidation';
                }

                $created = $approval->created_at
                    ? $approval->created_at->copy()->startOfDay()
                    : $today->copy();
                $daysWaiting = max(0, (int) $created->diffInDays($today));

                return [
                    'id' => $approval->id,
                    'kind' => $kind,
                    'type' => $approval->type,
                    'type_label' => $approval->typeLabel(),
                    'title' => $approval->type === 'liquidation'
                        ? ($approval->liquidation?->title ?? $approval->title)
                        : $approval->title,
                    'description' => $approval->description,
                    'project_id' => $approval->project_id,
                    'project' => $project['title'] ?? 'Unknown project',
                    'project_code' => $project['code'] ?? null,
                    'funder' => $project['funder'] ?? null,
                    'amount' => $approval->amount !== null ? (float) $approval->amount : null,
                    'requested_by' => $approval->requestedBy?->name,
                    'days_waiting' => $daysWaiting,
                    'created_at' => $approval->created_at?->toIso8601String(),
                ];
            })
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function forecast(Collection $projects, Carbon $today): array
    {
        $months = collect(range(0, 3))
            ->map(fn (int $offset) => $today->copy()->startOfMonth()->addMonths($offset));
        $totals = $months
            ->mapWithKeys(fn (Carbon $month) => [$month->format('Y-m') => 0.0])
            ->all();
        $releases = $months
            ->mapWithKeys(fn (Carbon $month) => [$month->format('Y-m') => []])
            ->all();

        foreach ($projects as $project) {
            foreach ($project->tranches as $tranche) {
                if ($tranche->status === 'released') {
                    continue;
                }

                $overdue = $tranche->planned_release_date
                    && $tranche->planned_release_date->lt($today->copy()->startOfMonth());
                $month = $tranche->planned_release_date
                    && ! $overdue
                    ? $tranche->planned_release_date->copy()->startOfMonth()
                    : $today->copy()->startOfMonth();

                $key = $month->format('Y-m');
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += (float) $tranche->amount;
                    $releases[$key][] = [
                        'id' => $tranche->id,
                        'name' => $tranche->name,
                        'project' => $project->title,
                        'project_code' => $project->code,
                        'amount' => round((float) $tranche->amount, 2),
                        'planned_release_date' => $tranche->planned_release_date?->toDateString(),
                        'overdue' => $overdue,
                    ];
                }
            }
        }

        return $months->map(fn (Carbon $month) => [
            'month' => $month->format('Y-m'),
            'label' => $month->format('M'),
            'amount' => round($totals[$month->format('Y-m')], 2),
            'releases' => $releases[$month->format('Y-m')],
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function funderOptions(): array
    {
        return FundingAgency::query()
            ->withCount('projects')
            ->orderBy('name')
            ->get()
            ->map(fn (FundingAgency $agency) => [
                'id' => $agency->id,
                'name' => $agency->name,
                'acronym' => $agency->acronym,
                'label' => $agency->acronym ?: $agency->name,
                'project_count' => (int) $agency->projects_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fundingSources(Collection $rows): array
    {
        return $rows
            ->groupBy('funder')
            ->map(function (Collection $group, string $label) {
                $allocated = (float) $group->sum('budget');
                $spent = (float) $group->sum('spent');

                return [
                    'funder' => $label,
                    'funder_id' => $group->first()['funder_id'] ?? null,
                    'projects' => $group->count(),
                    'allocated' => round($allocated, 2),
                    'spent' => round($spent, 2),
                    'utilization_pct' => $this->pct($spent, $allocated),
                ];
            })
            ->sortByDesc('allocated')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function deadlines(Collection $projects, Carbon $today): array
    {
        $items = collect();

        foreach ($projects as $project) {
            foreach ($project->reports as $report) {
                if (! $report->due_date) {
                    continue;
                }
                $items->push($this->deadline(
                    'report',
                    $report->title,
                    $project->title,
                    $report->due_date,
                    $today
                ));
            }

            foreach ($project->liquidations as $liquidation) {
                if (! $liquidation->due_date) {
                    continue;
                }
                $items->push($this->deadline(
                    'liquidation',
                    $liquidation->title,
                    $project->title,
                    $liquidation->due_date,
                    $today
                ));
            }

            foreach ($project->tranches as $tranche) {
                if ($tranche->status === 'released' || ! $tranche->planned_release_date) {
                    continue;
                }
                $items->push($this->deadline(
                    'tranche',
                    $tranche->name,
                    $project->title,
                    $tranche->planned_release_date,
                    $today
                ));
            }

            if ($project->end_date) {
                $items->push($this->deadline(
                    'project_end',
                    'Project end / terminal report',
                    $project->title,
                    $project->end_date,
                    $today
                ));
            }
        }

        return $items
            ->sortBy(fn (array $item) => $item['_date'])
            ->take(12)
            ->map(function (array $item) {
                unset($item['_date']);
                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function deadline(string $type, string $title, string $project, Carbon $date, Carbon $today): array
    {
        $days = (int) $today->diffInDays($date, false);

        if ($days < 0) {
            $status = abs($days) . ' days late';
            $tone = 'over';
        } elseif ($days === 0) {
            $status = 'Due today';
            $tone = 'warn';
        } else {
            $status = $days . ' days left';
            $tone = $days <= 14 ? 'warn' : 'ok';
        }

        return [
            'id' => $type . '-' . $date->format('Ymd') . '-' . md5($project . $title),
            'type' => $type,
            'title' => $title,
            'project' => $project,
            'date' => $date->toDateString(),
            'days' => $days,
            'status' => $status,
            'tone' => $tone,
            '_date' => $date->timestamp,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function exceptions(Collection $projects, Collection $rows): array
    {
        $rowMap = $rows->keyBy('id');
        $selfBenefiting = collect();
        $unused = collect();
        $realignments = collect();

        foreach ($projects as $project) {
            $row = $rowMap->get($project->id);
            $timePct = (float) ($row['time_pct'] ?? 0);

            foreach ($project->libCategories as $category) {
                $name = (string) $category->line_item_name;
                $spent = $category->spentAmount();
                $allocated = (float) $category->allocated_amount;

                $isSelfBenefiting = preg_match('/program\s*leader/i', $name)
                    && preg_match('/honorar/i', $name);

                if ($isSelfBenefiting) {
                    $selfBenefiting->push([
                        'project' => $project->title,
                        'project_id' => $project->id,
                        'line_item' => $name,
                        'allocated' => round($allocated, 2),
                        'spent' => round($spent, 2),
                        'spent_pct' => $this->pct($spent, $allocated),
                    ]);
                    continue;
                }

                if ($spent <= 0 && $timePct > 50) {
                    $unused->push([
                        'project' => $project->title,
                        'project_id' => $project->id,
                        'line_item' => $name,
                        'allocated' => round($allocated, 2),
                        'spent' => round($spent, 2),
                        'spent_pct' => $this->pct($spent, $allocated),
                        'time_pct' => $timePct,
                    ]);
                }
            }

            $categories = $project->libCategories->keyBy('id');

            foreach ($project->approvalRequests as $approval) {
                if ($approval->type !== 'budget_realignment') {
                    continue;
                }

                $payload = $approval->payload ?? [];
                $source = $categories->get((int) ($payload['from_lib_category_id'] ?? 0));
                $target = $categories->get((int) ($payload['to_lib_category_id'] ?? 0));
                $amount = (float) ($payload['amount'] ?? $approval->amount ?? 0);

                if (! $source || $amount <= 0) {
                    continue;
                }

                $threshold = (float) $source->allocated_amount * 0.10;
                if ($amount >= $threshold) {
                    $realignments->push([
                        'id' => $approval->id,
                        'project' => $project->title,
                        'project_id' => $project->id,
                        'amount' => round($amount, 2),
                        'source' => $source->line_item_name,
                        'source_allocated' => round((float) $source->allocated_amount, 2),
                        'target' => $target?->line_item_name,
                        'threshold_pct' => 10,
                    ]);
                }
            }
        }

        return [
            'self_benefiting' => $selfBenefiting->values()->all(),
            'unused_line_items' => $unused->values()->all(),
            'realignments' => $realignments->values()->all(),
        ];
    }

    protected function applyDecision(
        ApprovalRequest $approval,
        string $decision,
        ?string $remarks,
        User $actor
    ): void {
        if ($approval->liquidation) {
            $approval->liquidation->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'remarks' => $remarks,
            ]);
        }

        if ($decision !== 'approved') {
            return;
        }

        if ($approval->type === 'budget_realignment') {
            $payload = $approval->payload ?? [];
            $fromId = (int) ($payload['from_lib_category_id'] ?? 0);
            $toId = (int) ($payload['to_lib_category_id'] ?? 0);
            $amount = (float) ($payload['amount'] ?? $approval->amount ?? 0);

            if (! $fromId || ! $toId || $amount <= 0) {
                throw ValidationException::withMessages([
                    'payload' => ['This realignment request is missing its source, destination, or amount.'],
                ]);
            }

            if ($fromId === $toId) {
                throw ValidationException::withMessages([
                    'payload' => ['The source and destination line items must be different.'],
                ]);
            }

            $from = LibCategory::query()
                ->where('project_id', $approval->project_id)
                ->lockForUpdate()
                ->find($fromId);
            $to = LibCategory::query()
                ->where('project_id', $approval->project_id)
                ->lockForUpdate()
                ->find($toId);

            if (! $from || ! $to) {
                throw ValidationException::withMessages([
                    'payload' => ['One of the selected line items no longer exists.'],
                ]);
            }

            if ((float) $from->allocated_amount < $amount) {
                throw ValidationException::withMessages([
                    'payload' => ['The source line item does not have enough allocated budget.'],
                ]);
            }

            $from->decrement('allocated_amount', $amount);
            $to->increment('allocated_amount', $amount);
        }

        if ($approval->type === 'disbursement' && $approval->tranche) {
            $approval->tranche->update([
                'status' => 'released',
                'released_at' => $approval->tranche->released_at ?? now()->toDateString(),
            ]);
        }
    }

    protected function funderLabel(Project $project): string
    {
        if ($project->fundingAgency) {
            return $project->fundingAgency->acronym ?: $project->fundingAgency->name;
        }

        return 'Unassigned funder';
    }

    protected function pct(float $part, float $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
