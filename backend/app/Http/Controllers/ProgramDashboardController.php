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
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected'],
        ]);

        $approval = $this->dashboard->decide(
            $approval,
            $data['decision'],
            $data['remarks'] ?? null,
            $request->user()
        );

        return response()->json([
            'message' => $data['decision'] === 'approved'
                ? 'Approval recorded and related records updated.'
                : 'Request rejected.',
            'approval' => [
                'id' => $approval->id,
                'status' => $approval->status,
                'review_notes' => $approval->review_notes,
                'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            ],
        ]);
    }
}
