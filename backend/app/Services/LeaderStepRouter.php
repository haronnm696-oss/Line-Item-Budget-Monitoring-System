<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\PurchaseRequest;
use App\Models\User;

/**
 * A project has one Project Leader and the maker never approves their own
 * item, so nothing may wait for "another Project Leader". New submissions
 * are routed by the workflow services themselves; this moves on items that
 * were already waiting for the Project Leader step when its only possible
 * reviewer is their maker (older data, or the maker became the Project
 * Leader later):
 *
 *   expense           recorded by the Project Leader -> Finance/Admin
 *   purchase request  made by the Project Leader     -> no leader step
 *   payroll batch     pays / was prepared by the PL  -> Program Leader
 *   approved expense  no Finance/Admin may verify it -> Program Leader
 *                     (recorded by the only Finance/Admin: older data)
 *
 * Each move writes one audit entry ("rerouted") and notifies the next
 * reviewer. Runs after an account role change or a project-role Fix, and
 * from `php artisan workflow:reroute-leader-steps` for existing data.
 */
class LeaderStepRouter
{
    public function __construct(
        private ExpenseWorkflowService $expenses,
        private PurchaseRequestService $purchaseRequests,
        private PayrollService $payroll,
    ) {
    }

    /**
     * @param array<int, int>|null $projectIds limit to these projects (null = all)
     * @return array{expense: int, purchase_request: int, payroll: int, expense_verify: int}
     */
    public function reroute(User $actor, ?array $projectIds = null, bool $dryRun = false): array
    {
        $counts = ['expense' => 0, 'purchase_request' => 0, 'payroll' => 0, 'expense_verify' => 0];
        $scope = fn ($query) => $projectIds === null ? $query : $query->whereIn('project_id', $projectIds);

        foreach ($scope(Expense::query()->where('workflow_status', 'submitted'))->get() as $expense) {
            if ($dryRun ? $this->leads($expense->project_id, $expense->recorded_by) : $this->expenses->skipLeaderStep($expense, $actor)) {
                $counts['expense']++;
            }
        }

        foreach ($scope(PurchaseRequest::query()->where('workflow_status', 'submitted'))->get() as $request) {
            if ($dryRun ? $this->leads($request->project_id, $request->requested_by) : $this->purchaseRequests->skipLeaderStep($request, $actor)) {
                $counts['purchase_request']++;
            }
        }

        // Approved expenses nobody on Finance/Admin may verify: to the Program Leader.
        foreach ($scope(Expense::query()->where('workflow_status', 'endorsed'))->get() as $expense) {
            if ($expense->pendingEscalation() !== null || $this->expenses->eligibleVerifiers($expense)->isNotEmpty()) {
                continue;
            }
            if ($dryRun) {
                $counts['expense_verify']++;
            } elseif ($this->expenses->escalateVerifyIfNeeded($expense, $actor)->pendingEscalation() !== null) {
                $counts['expense_verify']++;
            }
        }

        if (! $dryRun) {
            foreach ($scope(PayrollBatch::query()->where('workflow_status', 'submitted'))->get() as $batch) {
                if ($this->payroll->rerouteLeaderStep($batch, $actor)) {
                    $counts['payroll']++;
                }
            }
        }

        return $counts;
    }

    private function leads(?int $projectId, $userId): bool
    {
        return $projectId && $userId && \App\Models\ProjectUser::query()
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->whereHas('role', fn ($q) => $q->where('slug', 'project-leader'))
            ->exists();
    }
}
