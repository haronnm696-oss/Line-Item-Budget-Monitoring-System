<?php

namespace App\Http\Controllers;

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
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::in([
                'all', 'fund-release', 'realignment', 'liquidation', 'budget-change',
                'disbursement', 'payroll', 'budget_realignment',
            ])],
            'project' => ['nullable', 'integer', 'exists:projects,id'],
            'funder' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->dashboard->approvalList(array_filter(
            $data,
            fn ($value) => $value !== null && $value !== '' && $value !== 'all'
        )));
    }
}
