<?php

namespace App\Http\Controllers;

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
    /**
     * Backend slug -> frontend role code.
     * Frontend codes (PRG/PL/RES/RA/FIN) drive dashboard routing,
     * sidebar visibility, and page guards.
     */
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
            'role' => $code, // frontend code: PRG | PL | RES | RA | FIN
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

        // Give deactivated accounts a distinct 403 instead of lumping them
        // into "Invalid email or password."
        $user = User::where('email', $email)->first();
        if ($user && !$user->is_active) {
            return response()->json([
                'message' => 'This account is deactivated. Contact your administrator.',
            ], 403);
        }

        // Laravel's built-in hashed-password verification via Auth::attempt.
        if (! Auth::attempt(['email' => $email, 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => self::toUserPayload($request->user())]);
    }

    /**
     * Public registration. Creates a new Gmail user, hashes the password,
     * assigns the selected role, and returns the same payload shape as login
     * so the frontend can sign the user in immediately.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email', 'regex:/@(gmail\.com|msumain\.edu\.ph)$/i'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'in:program-leader,project-leader,research-assistant,researcher'],
        ]);

        $email = strtolower(trim($data['email']));

        $role = Role::where('slug', $data['role'])->firstOrFail();

        $user = User::create([
            'name' => trim($data['name']),
            'email' => $email,
            'password' => Hash::make($data['password']),
            'role_id' => $role->id,
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

    /**
     * Kicks off a password-reset flow for a registered Gmail address.
     * Always returns a generic success so the endpoint cannot be used to
     * enumerate whether an email address is registered.
     */
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

    /**
     * Resets a user's password using a valid, unexpired reset token.
     */
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
