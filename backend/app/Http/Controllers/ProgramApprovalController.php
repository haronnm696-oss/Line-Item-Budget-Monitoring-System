<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ProgramDashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramApprovalController extends Controller
{
    public function __construct(private ProgramDashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            // "rejected" (older links) is the Returned tab.
            'status' => ['nullable', Rule::in(array_merge(
                array_keys(ApprovalRequest::STATUS_LABELS),
                [ApprovalRequest::STORED_RETURNED]
            ))],
            // One list of types, shared with the frontend (returned as `types`).
            'type' => ['nullable', Rule::in(ApprovalRequest::filterTypeValues())],
            'project' => ['nullable', 'integer', 'exists:projects,id'],
            'funder' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->dashboard->approvalList(array_filter(
            $data,
            fn ($value) => $value !== null && $value !== '' && $value !== 'all'
        )));
    }
}
