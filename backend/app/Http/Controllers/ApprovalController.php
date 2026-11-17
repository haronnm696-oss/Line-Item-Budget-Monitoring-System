<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ApprovalDetailService;
use App\Services\DashboardService;
use Illuminate\Http\Request;

/**
 * Read-only list of approval requests for the projects the user can see.
 *
 * Decisions (approve / reject) are made only by the Program Leader through
 * POST /api/program/approvals/{approval}/decision. The former
 * PATCH /api/approvals/{id}/approve and /reject endpoints were removed: no
 * page used them, and they also let Finance/Admin decide budget requests.
 */
class ApprovalController extends Controller
{
    public function __construct(private DashboardService $dashboard, private ApprovalDetailService $details)
    {
    }

    /**
     * GET /api/approvals/{approval}: one request with its status history,
     * type-specific figures and attachments (the review side panel).
     * project.access: the Program Leader and members of its project only.
     */
    public function show(Request $request, ApprovalRequest $approval)
    {
        return response()->json(['approval' => $this->details->detail($approval, $request->user())]);
    }

    /** List approvals, defaulting to the pending queue. */
    public function index(Request $request)
    {
        $user = $request->user();
        $projectIds = $user->accessibleProjectIds();

        $query = ApprovalRequest::query()
            ->with(['requestedBy', 'tranche'])
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->orderByDesc('created_at');

        if ($request->filled('project_id')) {
            $projectId = (int) $request->query('project_id');
            abort_unless($user->canAccessProject($projectId), 403, 'You are not assigned to this project.');
            $query->where('project_id', $projectId);
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
}
