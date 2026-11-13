<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use App\Notifications\NewRegistrationPendingNotification;
use App\Notifications\PasswordChangedNotification;
use App\Rules\StrongPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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
            'status' => $user->role_id === null ? 'Pending' : ($user->is_active ? 'Active' : 'Inactive'),
            'is_active' => (bool) $user->is_active,
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
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/@(gmail\.com|(?:s\.)?msumain\.edu\.ph)$/i'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $email = strtolower(trim($data['email']));

        $user = User::where('email', $email)->first();
        if ($user && $user->registration_rejected_at !== null) {
            return response()->json([
                'message' => 'Your registration was not approved. Contact the Program Leader.',
            ], 403);
        }
        if ($user && $user->role_id === null) {
            return response()->json([
                'message' => 'Your account is awaiting Program Leader approval.',
            ], 403);
        }
        if ($user && ! $user->is_active) {
            return response()->json([
                'message' => 'This account is deactivated. Contact your administrator.',
            ], 403);
        }

        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $request->session()->regenerate();

        $authenticated = Auth::guard('web')->user();
        $authenticated->forceFill(['last_login_at' => now()])->save();

        return response()->json(['user' => self::toUserPayload($authenticated)]);
    }

    public function register(Request $request)
    {
        // Normalize before validation so the unique rule also catches
        // case-only duplicates and the stored address is always lowercase.
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/^[^@\s]+@s\.msumain\.edu\.ph$/i', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                new StrongPassword(
                    (string) $request->input('first_name'),
                    (string) $request->input('last_name'),
                    (string) $request->input('email')
                ),
            ],
            'employee_id' => ['nullable', 'string', 'max:255', 'regex:/^MSU-\d{5}$/i', 'unique:users,employee_id'],
            'contact_number' => ['required', 'string', 'size:11', 'regex:/^09[0-9]{9}$/'],
            'college' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
        ], [
            'contact_number.required' => 'Ang numero ng tawag ay kailangan. (Contact number is required.)',
            'contact_number.string' => 'Ang numero ng tawag ay dapat isama sa teksto. (Contact number must be a string.)',
            'contact_number.size' => 'Ang numero ng tawag ay dapat 11 na digit. (Contact number must be 11 digits.)',
            'contact_number.regex' => 'Ang numero ng tawag ay dapat nagsisimula sa 09 at may 11 na digit. (Contact number must start with 09 and have 11 digits.)',
        ]);

        $email = $data['email'];

        // The employee ID is its own field and must never be derived from the
        // email address (or any other input).
        $employeeId = isset($data['employee_id']) ? trim($data['employee_id']) : null;
        $employeeId = $employeeId === '' ? null : $employeeId;

        if ($employeeId !== null && strcasecmp($employeeId, $email) === 0) {
            throw ValidationException::withMessages([
                'employee_id' => ['The employee ID cannot be the same as the email address.'],
            ]);
        }

        // Public registration never assigns a role. The account stays pending
        // and inactive until a Program Leader or Finance/Admin approves it.
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
            'role_id' => null,
            'position' => $data['position'],
            'email_verified_at' => now(),
            'is_active' => false,
        ]);

        $programLeaders = User::query()
            ->whereHas('primaryRole', fn ($query) => $query->where('slug', 'program-leader'))
            ->get();

        Notification::send($programLeaders, new NewRegistrationPendingNotification($user));

        return response()->json([
            'user' => self::toUserPayload($user),
            'pending' => true,
            'message' => 'Account submitted. A Program Leader must approve it and assign a role before you can sign in.',
        ], 201);
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
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/@(gmail\.com|(?:s\.)?msumain\.edu\.ph)$/i'],
        ]);

        $email = strtolower(trim($data['email']));

        // Apply the per-email limits to every valid email, whether or not an
        // account exists. That keeps throttling from becoming an enumeration
        // signal.
        $emailKey = 'password-reset-email:' . hash('sha256', $email);
        $cooldownKey = 'password-reset-cooldown:' . hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($emailKey, 3)) {
            return $this->passwordResetThrottleResponse(RateLimiter::availableIn($emailKey));
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $retryAfter = RateLimiter::availableIn($cooldownKey);

            // Allow the request when the cooldown is within the final second.
            // The UI countdown and the server clock can disagree by a fraction
            // of a second, which would otherwise turn a valid 60-second resend
            // into a false 429.
            if ($retryAfter > 1) {
                return $this->passwordResetThrottleResponse($retryAfter);
            }
        }

        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($cooldownKey, 60);

        $user = User::where('email', $email)->first();
        $eligible = $user
            && $user->is_active
            && $user->role_id !== null
            && $user->registration_rejected_at === null;

        if ($eligible) {
            // Create the token and send the mail after the response has gone
            // out, so active and unknown addresses take the same time.
            app()->terminating(function () use ($email) {
                static $sent = false;

                if ($sent) {
                    return;
                }

                $sent = true;

                try {
                    Password::sendResetLink(['email' => $email]);
                } catch (\Throwable $e) {
                    // Never log the token, password, or whether the address exists.
                    Log::error('Password reset notification failed.');
                }
            });
        }

        return response()->json([
            'message' => 'If an account exists for that address, a password reset link has been sent.',
        ], 202);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/@(gmail\.com|(?:s\.)?msumain\.edu\.ph)$/i'],
            'token' => ['required', 'string'],
        ]);

        $email = strtolower(trim($data['email']));
        $token = $data['token'];
        $user = User::where('email', $email)->first();

        // Check the one-time token before the password policy so a bad,
        // expired, or already-used link always maps to the "Link expired"
        // screen instead of a password error.
        if (! $user || ! Password::broker()->tokenExists($user, $token)) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        $data = $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                new StrongPassword(
                    $user->first_name ?? '',
                    $user->last_name ?? '',
                    $email
                ),
            ],
        ]);

        $status = Password::reset(
            [
                'email' => $email,
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $token,
            ],
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $this->revokeUserSessionsAndTokens($user);
                $this->recordPasswordResetAudit($user, $request);

                try {
                    $user->notify(new PasswordChangedNotification());
                } catch (\Throwable $e) {
                    Log::error('Password changed notification failed.');
                }
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ], 200);
    }

    private function passwordResetThrottleResponse(int $retryAfter)
    {
        return response()->json([
            'message' => 'Too many reset requests. Please wait before requesting another link.',
        ], 429)->header('Retry-After', max(1, $retryAfter));
    }

    private function revokeUserSessionsAndTokens(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            $user->tokens()->delete();
        }
    }

    private function recordPasswordResetAudit(User $user, Request $request): void
    {
        AuditTrail::create([
            'user_id' => $user->id,
            'action' => 'Password reset',
            'model_type' => 'User',
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => null,
            'reason' => 'Password reset via emailed link',
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
