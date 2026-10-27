<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /** List approvals, defaulting to the pending queue. */
    public function index(Request $request)
    {
        $query = ApprovalRequest::query()
            ->with(['requestedBy', 'tranche'])
            ->orderByDesc('created_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where('status', 'pending');
        }

        $approvals = $query->get()
            ->map(fn (ApprovalRequest $approval) => $this->dashboard->approvalRow($approval))
            ->all();

        return response()->json(['approvals' => $approvals]);
    }

    /** Approve a pending disbursement, tranche release, or realignment. */
    public function approve(Request $request, ApprovalRequest $approval)
    {
        return $this->review($request, $approval, 'approved');
    }

    /** Reject a pending request with an optional reason. */
    public function reject(Request $request, ApprovalRequest $approval)
    {
        return $this->review($request, $approval, 'rejected');
    }

    protected function review(Request $request, ApprovalRequest $approval, string $status)
    {
        $this->authorizeReviewer($request);

        if (! $approval->isPending()) {
            return response()->json([
                'message' => 'This approval request has already been reviewed.',
            ], 409);
        }

        DB::transaction(function () use ($request, $approval, $status) {
            $oldValues = $approval->only(['status', 'reviewed_by', 'reviewed_at', 'review_notes']);

            $approval->update([
                'status' => $status,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $request->input('review_notes'),
            ]);

            if ($status === 'approved') {
                $this->applyApprovedRequest($approval);
            }

            AuditTrail::log(
                $status === 'approved' ? 'approved' : 'rejected',
                $approval,
                $oldValues,
                $approval->fresh()->toArray()
            );
        });

        $approval->refresh()->load(['requestedBy', 'tranche']);

        return response()->json([
            'message' => $status === 'approved'
                ? 'Approval recorded and related records updated.'
                : 'Request rejected.',
            'approval' => $this->dashboard->approvalRow($approval),
        ]);
    }

    /**
     * Apply side effects for approved requests:
     * - budget realignment moves allocation between two line items;
     * - fund-release requests mark their linked tranche as released.
     */
    protected function applyApprovedRequest(ApprovalRequest $approval): void
    {
        $payload = $approval->payload ?? [];

        if ($approval->type === 'budget_realignment') {
            $fromId = isset($payload['from_lib_category_id']) ? (int) $payload['from_lib_category_id'] : null;
            $toId = isset($payload['to_lib_category_id']) ? (int) $payload['to_lib_category_id'] : null;
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
                ->find($fromId);
            $to = LibCategory::query()
                ->where('project_id', $approval->project_id)
                ->find($toId);

            if (! $from || ! $to) {
                throw ValidationException::withMessages([
                    'payload' => ['One of the selected line items no longer exists.'],
                ]);
            }

            if ((float) $from->allocated_amount < $amount) {
                throw ValidationException::withMessages([
                    'payload' => ['The source line item does not have enough allocated budget for this realignment.'],
                ]);
            }

            $from->decrement('allocated_amount', $amount);
            $to->increment('allocated_amount', $amount);
        }

        if ($approval->project_tranche_id && $approval->tranche) {
            $approval->tranche->update([
                'status' => 'released',
                'released_at' => $approval->tranche->released_at ?? now()->toDateString(),
            ]);
        }
    }

    protected function authorizeReviewer(Request $request): void
    {
        $slug = $request->user()?->primaryRole?->slug;

        abort_unless(
            in_array($slug, ['program-leader', 'finance-admin'], true),
            403,
            'Only Program Leaders or Finance/Admin staff can review approval requests.'
        );
    }
}
