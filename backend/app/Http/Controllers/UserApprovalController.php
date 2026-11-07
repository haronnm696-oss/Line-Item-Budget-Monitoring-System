<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Program Leader / Finance-Admin approval queue for self-registered accounts.
 *
 * Pending accounts are represented with role_id = null and is_active = false,
 * so no schema change is required.
 */
class UserApprovalController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeApprover($request);

        $users = User::query()
            ->whereNull('role_id')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'users' => $users->map(fn (User $user) => $this->pendingPayload($user))->values()->all(),
        ]);
    }

    public function approve(Request $request, User $user)
    {
        $this->authorizeApprover($request);

        if ($user->role_id !== null) {
            return response()->json([
                'message' => 'This account already has a role assigned.',
            ], 409);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json([
                'message' => 'Please verify your email before the account can be reviewed.',
            ], 403);
        }

        $data = $request->validate([
            'role' => ['required', 'string', 'in:program-leader,project-leader,research-assistant,researcher,finance-admin'],
        ]);

        $role = Role::where('slug', $data['role'])->firstOrFail();

        DB::transaction(function () use ($request, $user, $role) {
            $oldValues = $user->only(['role_id', 'is_active', 'position']);

            $user->update([
                'role_id' => $role->id,
                'is_active' => true,
                'position' => $role->name,
            ]);

            AuditTrail::create([
                'user_id' => $request->user()->id,
                'action' => 'approved',
                'model_type' => 'User',
                'model_id' => $user->id,
                'old_values' => $oldValues,
                'new_values' => $user->fresh()->only(['role_id', 'is_active', 'position']),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        Notification::send($user, new AccountApprovedNotification($user->fresh(), $role->name));

        return response()->json([
            'message' => 'Account approved and role assigned.',
            'user' => AuthController::toUserPayload($user->fresh()),
        ]);
    }

    protected function authorizeApprover(Request $request): void
    {
        $slug = $request->user()?->primaryRole?->slug;

        abort_unless(
            in_array($slug, ['program-leader', 'finance-admin'], true),
            403,
            'Only Program Leaders or Finance/Admin staff can approve accounts.'
        );
    }

    protected function pendingPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'college' => $user->college,
            'department' => $user->department,
            'contact_number' => $user->contact_number,
            'status' => 'Pending',
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
