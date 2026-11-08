<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProgramUserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramUserController extends Controller
{
    public function __construct(private ProgramUserService $users)
    {
    }

    public function registrations()
    {
        return response()->json([
            'users' => $this->users->pendingRegistrations(),
            'roles' => $this->roleOptions(ProgramUserService::PROJECT_ROLES),
        ]);
    }

    public function decideRegistration(Request $request, User $user)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'role' => ['nullable', 'string', 'required_if:decision,approved', Rule::in(ProgramUserService::PROJECT_ROLES)],
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected'],
        ]);

        $user = $this->users->decideRegistration(
            $user,
            $data['decision'],
            $data['role'] ?? null,
            $data['remarks'] ?? null,
            $request->user()
        );

        return response()->json([
            'message' => $data['decision'] === 'approved'
                ? 'Account approved and role assigned. You can now assign the user to a project.'
                : 'Registration rejected. The user has been notified.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        return response()->json([
            'users' => $this->users->users($data['project_id'] ?? null),
            'roles' => $this->roleOptions(ProgramUserService::ACCOUNT_ROLES),
            'project_roles' => $this->roleOptions(ProgramUserService::PROJECT_ROLES),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'employee_id' => strtoupper(trim((string) $request->input('employee_id'))),
        ]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email:rfc', 'max:255',
                'regex:/^[^@\s]+@msumain\.edu\.ph$/i',
                'unique:users,email',
            ],
            'employee_id' => ['required', 'string', 'max:255', 'regex:/^MSU-\d{5}$/i', 'unique:users,employee_id'],
            'position' => ['required', 'string', 'max:255'],
            'college' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'size:11', 'regex:/^09[0-9]{9}$/'],
            'role' => ['required', 'string', Rule::in(ProgramUserService::ACCOUNT_ROLES)],
        ]);

        $user = $this->users->addUser($data, $request->user());

        return response()->json([
            'message' => 'Account created. A password setup link has been emailed to the user.',
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function updateStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $this->users->setActive(
            $user,
            (bool) $data['is_active'],
            $data['reason'] ?? null,
            $request->user()
        );

        return response()->json([
            'message' => $user->is_active ? 'Account reactivated.' : 'Account deactivated.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(ProgramUserService::ACCOUNT_ROLES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $this->users->updatePrimaryRole(
            $user,
            $data['role'],
            $data['reason'] ?? null,
            $request->user()
        );

        return response()->json([
            'message' => 'Role updated.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'role' => ['required', 'string', Rule::in(ProgramUserService::PROJECT_ROLES)],
        ]);

        $user = User::findOrFail($data['user_id']);
        $project = Project::findOrFail($data['project_id']);

        $this->users->assign($user, $project, $data['role'], $request->user());

        return response()->json([
            'message' => 'User assigned to project.',
            'user' => $this->userPayload($user->fresh()->load('primaryRole', 'projectUsers.project', 'projectUsers.role')),
        ], 201);
    }

    public function updateAssignment(Request $request, User $user, Project $project)
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(ProgramUserService::PROJECT_ROLES)],
        ]);

        $this->users->updateAssignment($user, $project, $data['role'], $request->user());

        return response()->json([
            'message' => 'Project role updated.',
            'user' => $this->userPayload($user->fresh()->load('primaryRole', 'projectUsers.project', 'projectUsers.role')),
        ]);
    }

    public function removeAssignment(Request $request, User $user, Project $project)
    {
        $this->users->removeAssignment($user, $project, $request->user());

        return response()->json([
            'message' => 'User removed from project.',
            'user' => $this->userPayload($user->fresh()->load('primaryRole', 'projectUsers.project', 'projectUsers.role')),
        ]);
    }

    protected function userPayload(User $user): array
    {
        $slug = $user->primaryRole?->slug;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'position' => $user->position,
            'role' => $slug ? (AuthController::SLUG_TO_CODE[$slug] ?? null) : null,
            'role_slug' => $slug,
            'role_label' => $user->primaryRole?->name,
            'status' => $user->is_active ? 'Active' : 'Inactive',
            'is_active' => (bool) $user->is_active,
        ];
    }

    /**
     * @param array<int, string> $slugs
     * @return array<int, array{slug: string, label: string}>
     */
    protected function roleOptions(array $slugs): array
    {
        return array_map(fn (string $slug) => [
            'slug' => $slug,
            'label' => ProgramUserService::ROLE_LABELS[$slug] ?? $slug,
        ], $slugs);
    }
}
