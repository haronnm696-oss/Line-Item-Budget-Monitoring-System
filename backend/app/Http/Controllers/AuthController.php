<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public const SLUG_TO_CODE = [
        'program-leader' => 'PRG',
        'project-leader' => 'PL',
        'researcher' => 'RES',
        'research-assistant' => 'RA',
        'finance-admin' => 'FIN',
    ];

    public static function toUserPayload(User $user): array
    {
        $user->loadMissing('primaryRole');
        $slug = $user->primaryRole?->slug;
        $code = $slug ? (self::SLUG_TO_CODE[$slug] ?? null) : null;
        $label = $user->primaryRole?->name ?? 'No role assigned';

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'role' => $code,
            'role_slug' => $slug,
            'roleLabel' => $label,
            'avatar_initials' => self::initials($user->name),
        ];
    }

    protected static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        $parts = array_values(array_filter($parts));
        if (count($parts) === 0) return 'U';
        if (count($parts) === 1) return strtoupper(substr($parts[0], 0, 2));
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/@(gmail\.com|msumain\.edu\.ph)$/i'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $email = strtolower(trim($data['email']));

        $user = User::where('email', $email)->first();
        if ($user && ! $user->is_active) {
            return response()->json([
                'message' => 'This account is deactivated. Contact your administrator.',
            ], 403);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => self::toUserPayload($request->user())]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/@(gmail\.com|msumain\.edu\.ph)$/i'],
            'password' => ['required', 'string', 'min:8'],
            'employee_id' => ['nullable', 'string', 'max:255', 'unique:users,employee_id'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'college' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:program-leader,project-leader,research-assistant,researcher,finance-admin'],
        ]);

        $email = strtolower(trim($data['email']));

        // The employee ID is its own field and must never be derived from the
        // email address (or any other input).
        $employeeId = isset($data['employee_id']) ? trim($data['employee_id']) : null;
        $employeeId = $employeeId === '' ? null : $employeeId;

        if ($employeeId !== null && strcasecmp($employeeId, $email) === 0) {
            throw ValidationException::withMessages([
                'employee_id' => ['The employee ID cannot be the same as the email address.'],
            ]);
        }

        $role = Role::where('slug', $data['role'])->firstOrFail();

        $positionMap = [
            'program-leader' => 'Program Leader',
            'project-leader' => 'Project Leader',
            'research-assistant' => 'Research Assistant',
            'researcher' => 'Researcher',
            'finance-admin' => 'Finance Officer',
        ];

        $user = User::create([
            'name' => trim($data['first_name'] . ' ' . $data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'employee_id' => $employeeId,
            'email' => $email,
            'password' => Hash::make($data['password']),
            'contact_number' => $data['contact_number'] ?? null,
            'college' => $data['college'] ?? null,
            'department' => $data['department'] ?? null,
            'role_id' => $role->id,
            'position' => $positionMap[$data['role']] ?? 'Research Assistant',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        return response()->json(['user' => self::toUserPayload($user)], 201);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json(['user' => self::toUserPayload($user)]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $email = strtolower(trim($data['email']));
        $token = Str::random(60);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $email],
            ['token' => $token, 'created_at' => now()]
        );

        $resetUrl = rtrim(config('app.url', ''), '/')
            . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);

        try {
            Mail::send([], [], function ($message) use ($email, $resetUrl) {
                $message->to($email)
                    ->subject('Reset your LIB Monitoring System password')
                    ->html(
                        '<p>You requested a password reset for your LIB Monitoring System account.</p>' .
                        '<p><a href="' . e($resetUrl) . '">Reset your password</a></p>' .
                        '<p>This link expires in 60 minutes. If you did not request this, you can safely ignore this email.</p>'
                    );
            });
        } catch (\Throwable $e) {
            Log::error('Password reset email failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'If an account exists for that address, a password reset link has been sent.',
        ], 202);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $record = DB::table('password_resets')
            ->where('email', $data['email'])
            ->where('token', $data['token'])
            ->first();

        if (! $record || $record->created_at < now()->subMinutes(60)) {
            throw ValidationException::withMessages([
                'token' => ['This password reset token is invalid or expired.'],
            ]);
        }

        $user = User::where('email', $data['email'])->first();
        $user->update(['password' => Hash::make($data['password'])]);

        DB::table('password_resets')->where('email', $data['email'])->delete();

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ], 200);
    }
}
