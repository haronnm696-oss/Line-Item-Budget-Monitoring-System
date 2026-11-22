<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProgramUserService;
use App\Support\AcademicUnits;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramUserController extends Controller
{
    public function __construct(private ProgramUserService $users)
    {
    }

    public function registrations()
    {
        $users = $this->users->pendingRegistrations();

        return response()->json([
            'users' => $users,
            'counts' => ['pending' => $users->count()],
            // Account roles for the Review panel; project roles for its assignment rows.
            'roles' => $this->roleOptions(ProgramUserService::ACCOUNT_ROLES),
            'project_roles' => $this->roleOptions(ProgramUserService::PROJECT_ROLES),
            'projects' => $this->projectOptions(),
        ]);
    }

    /** Pending count only, for the "Users and roles" menu badge. */
    public function registrationCount()
    {
        return response()->json(['counts' => ['pending' => User::query()
            ->whereNull('role_id')
            ->whereNull('registration_rejected_at')
            ->count()]]);
    }

    /**
     * Approve with the account role and optional project assignments (one
     * transaction), or reject with a reason. Without assignments this is
     * the earlier "approve, then Manage" flow.
     */
    public function decideRegistration(Request $request, User $user)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'role' => ['nullable', 'string', 'required_if:decision,approved', Rule::in(ProgramUserService::ACCOUNT_ROLES)],
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected'],
            'assignments' => ['nullable', 'array', 'max:50'],
            'assignments.*.project_id' => ['required', 'integer', 'exists:projects,id'],
            // Optional: the role on each project is the account role.
            'assignments.*.role' => ['nullable', 'string'],
        ], [
            'remarks.required_if' => 'A reason for rejection is required.',
            'assignments.*.project_id.required' => 'Choose a project.',
            'assignments.*.project_id.exists' => 'This project no longer exists.',
        ]);

        $user = $this->users->decideRegistration(
            $user,
            $data['decision'],
            $data['role'] ?? null,
            $data['remarks'] ?? null,
            $request->user(),
            $data['assignments'] ?? []
        );

        $assigned = $user->projectUsers->count();

        return response()->json([
            'message' => $data['decision'] === 'approved'
                ? ($assigned > 0
                    ? 'Account approved with its role and ' . $assigned . ' project assignment' . ($assigned === 1 ? '' : 's') . '.'
                    : 'Account approved and role assigned. You can now assign the user to a project.')
                : 'Registration rejected. The user has been notified.',
            'user' => $this->userPayload($user),
        ]);
    }

    /** Id, title, code, status and current Project Leader of every project. */
    protected function projectOptions(): array
    {
        return $this->users->assignableProjects();
    }

    /**
     * One row per person. Filters: q (name or employee ID), role, project,
     * status (active by default, inactive, all), user (focus), page.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(ProgramUserService::ACCOUNT_ROLES)],
            'project' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'user' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $directory = $this->users->directory($data, $request->user());

        return response()->json($directory + [
            'roles' => $this->roleOptions(ProgramUserService::ACCOUNT_ROLES),
            'project_roles' => $this->roleOptions(ProgramUserService::PROJECT_ROLES),
            'projects' => $this->projectOptions(),
            'assignable_users' => $this->users->assignableUsers(),
        ]);
    }

    public function updateDetails(Request $request, User $user)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'employee_id' => strtoupper(trim((string) $request->input('employee_id'))) ?: null,
            'name' => trim((string) $request->input('name')),
            'position' => trim((string) $request->input('position')) ?: null,
        ] + ($request->has('contact_number') ? ['contact_number' => trim((string) $request->input('contact_number')) ?: null] : []));

        abort_if($user->role_id === null, 422, 'Pending registrations are reviewed above, not edited here.');

        // Legacy values (e.g. a gmail.com address or "MSU-DEMO01") are kept as
        // they are; the format rules apply only to a value that is changed.
        $emailChanged = $request->input('email') !== strtolower(trim((string) $user->email));
        $idChanged = $request->input('employee_id') !== (strtoupper(trim((string) $user->employee_id)) ?: null);
        // Same for the contact number: a saved number that does not match is
        // kept only while unchanged; a new or changed one must match.
        $contactChanged = $request->input('contact_number') !== (trim((string) $user->contact_number) ?: null);

        // College / department only when the form sends them; a saved pair not
        // in the list is kept while unchanged (AcademicUnits::check).
        $data = AcademicUnits::validateRequest($request, [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => array_merge(
                ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                $emailChanged ? ['regex:/^[^@\s]+@(?:s\.)?msumain\.edu\.ph$/i'] : []
            ),
            'employee_id' => array_merge(
                [$idChanged ? 'required' : 'nullable', 'string', 'max:50', Rule::unique('users', 'employee_id')->ignore($user->id)],
                $idChanged ? ['regex:/^MSU-\d{5}$/'] : []
            ),
            // Optional; sent by the Edit details form (absent = left as it is).
            'contact_number' => $contactChanged ? array_merge(['sometimes'], User::contactNumberRules()) : ['sometimes', 'nullable', 'string', 'max:50'],
        ], User::contactNumberMessages() + [
            'email.unique' => 'Another account already uses this email.',
            'email.regex' => 'A new email must end with @msumain.edu.ph.',
            'employee_id.unique' => 'Another account already uses this employee ID.',
            'employee_id.required' => 'A new employee ID must use the format MSU-00000.',
            'employee_id.regex' => 'A new employee ID must use the format MSU-00000.',
        ], required: false, existing: $user);

        $user = $this->users->updateDetails($user, $data, $request->user());

        return response()->json([
            'message' => 'Account details updated.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'employee_id' => strtoupper(trim((string) $request->input('employee_id'))),
        ]);

        $data = AcademicUnits::validateRequest($request, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email:rfc', 'max:255',
                'regex:/^[^@\s]+@(?:s\.)?msumain\.edu\.ph$/i',
                'unique:users,email',
            ],
            'employee_id' => ['required', 'string', 'max:255', 'regex:/^MSU-\d{5}$/i', 'unique:users,employee_id'],
            'position' => ['required', 'string', 'max:255'],
            'contact_number' => User::contactNumberRules(),
            'role' => ['required', 'string', Rule::in(ProgramUserService::ACCOUNT_ROLES)],
        ], User::contactNumberMessages());

        $user = $this->users->addUser($data, $request->user());

        $emailed = $this->users->setupEmailSent();

        return response()->json([
            'message' => $emailed
                ? 'Account created. A password setup link has been emailed to the user.'
                : 'Account created, but the setup email could not be sent. Ask the user to use "Forgot password" on the sign-in page.',
            'setup_email_sent' => $emailed,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function updateStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            // A reason is required to deactivate, optional to reactivate.
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:is_active,false,0'],
        ], [
            'reason.required_if' => 'A reason is required to deactivate an account.',
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
            // Optional: the role on a project is always the account role
            // (a different one is refused by the service).
            'role' => ['nullable', 'string'],
        ], [
            'user_id.required' => 'Choose a user.',
            'project_id.required' => 'Choose a project.',
        ]);

        $user = User::findOrFail($data['user_id']);
        $project = Project::findOrFail($data['project_id']);

        $this->users->assign($user, $project, $data['role'] ?? null, $request->user());

        return response()->json([
            'message' => 'User assigned to project.',
            'user' => $this->userPayload($user->fresh()->load('primaryRole', 'projectUsers.project', 'projectUsers.role')),
        ], 201);
    }

    public function updateAssignment(Request $request, User $user, Project $project)
    {
        // "Fix": the role becomes the account role; a different one is refused.
        $data = $request->validate([
            'role' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->users->updateAssignment($user, $project, $data['role'] ?? null, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'message' => 'Role on this project now matches the account role.',
            'user' => $this->userPayload($user->fresh()->load('primaryRole', 'projectUsers.project', 'projectUsers.role')),
        ]);
    }

    public function removeAssignment(Request $request, User $user, Project $project)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'A reason is required to remove someone from a project.',
        ]);

        $this->users->removeAssignment($user, $project, $request->user(), $data['reason'] ?? null);

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
            'college' => $user->college,
            'department' => $user->department,
            'contact_number' => $user->contact_number,
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
