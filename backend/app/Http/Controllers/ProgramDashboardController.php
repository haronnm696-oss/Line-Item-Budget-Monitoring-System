<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ProgramDashboardService;
use Illuminate\Http\Request;

class ProgramDashboardController extends Controller
{
    public function __construct(private ProgramDashboardService $dashboard)
    {
    }

    public function overview(Request $request)
    {
        $data = $request->validate([
            'funder' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->dashboard->overview($data['funder'] ?? null));
    }

    public function decision(Request $request, ApprovalRequest $approval)
    {
        // Approve, or Return with a reason. "rejected" is still accepted from
        // older clients and means the same as "returned".
        $data = $request->validate([
            'decision' => ['required', 'in:approved,returned,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,returned', 'required_if:decision,rejected', 'not_regex:/^\s*$/'],
        ], [
            'remarks.required_if' => 'A reason is required to return a request.',
            'remarks.not_regex' => 'A reason is required to return a request.',
        ]);
        $approve = $data['decision'] === 'approved';

        $approval = $this->dashboard->decide(
            $approval,
            $approve ? 'approved' : 'returned',
            $approve ? ($data['remarks'] ?? null) : trim((string) $data['remarks']),
            $request->user()
        );

        return response()->json([
            'message' => $approve
                ? 'Approval recorded and related records updated.'
                : 'Request returned to the requester with your reason.',
            'approval' => [
                'id' => $approval->id,
                'status' => $approval->displayStatus(),
                'review_notes' => $approval->review_notes,
                'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            ],
        ]);
    }
}
