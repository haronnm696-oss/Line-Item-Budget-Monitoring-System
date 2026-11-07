<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expense verification endpoints. Maker-checker is enforced here: the person
 * who recorded an expense can never verify or reject it.
 */
class ExpenseController extends Controller
{
    public function verify(Request $request, Expense $expense)
    {
        return $this->review($request, $expense, 'verified');
    }

    public function reject(Request $request, Expense $expense)
    {
        return $this->review($request, $expense, 'rejected');
    }

    protected function review(Request $request, Expense $expense, string $status)
    {
        $this->authorizeVerifier($request);

        if ((int) $expense->recorded_by === (int) $request->user()->id) {
            return response()->json([
                'message' => 'You cannot verify or reject an expense you recorded.',
            ], 403);
        }

        if ($expense->status !== 'pending') {
            return response()->json([
                'message' => 'This expense has already been reviewed.',
            ], 409);
        }

        $data = [];
        if ($status === 'rejected') {
            $data = $request->validate([
                'reason' => ['required', 'string', 'max:2000'],
            ]);
        }

        DB::transaction(function () use ($request, $expense, $status, $data) {
            $oldValues = $expense->only(['status', 'verified_by', 'verified_at', 'remarks']);

            $expense->update([
                'status' => $status,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'remarks' => $status === 'rejected'
                    ? $data['reason']
                    : 'Verified by ' . $request->user()->name . '.',
            ]);

            AuditTrail::log(
                $status === 'verified' ? 'verified' : 'rejected',
                $expense,
                $oldValues,
                $expense->fresh()->toArray()
            );
        });

        return response()->json([
            'message' => $status === 'verified' ? 'Expense verified.' : 'Expense rejected.',
            'expense' => $expense->fresh()->load(['libCategory', 'recordedBy', 'verifiedBy']),
        ]);
    }

    protected function authorizeVerifier(Request $request): void
    {
        $slug = $request->user()?->primaryRole?->slug;

        abort_unless(
            in_array($slug, ['program-leader', 'finance-admin'], true),
            403,
            'Only Program Leaders or Finance/Admin staff can verify expenses.'
        );
    }
}
