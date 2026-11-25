<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use App\Notifications\NewRegistrationPendingNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Rules\StrongPassword;
use App\Services\PasswordResetCodeService;
use App\Support\AcademicUnits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
            'email' => ['required', 'email:rfc', 'max:255'],
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

        // College / department: one list (config/academic_units.php), checked with the other fields.
        $data = AcademicUnits::validateRequest($request, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                new StrongPassword(
                    (string) $request->input('first_name'),
                    (string) $request->input('last_name'),
                    (string) $request->input('email')
                ),
            ],
            'contact_number' => User::contactNumberRules(required: true),
            'position' => ['required', 'string', 'max:255'],
        ], [
            'contact_number.required' => 'Ang numero ng tawag ay kailangan. (Contact number is required.)',
        ] + User::contactNumberMessages());

        $email = $data['email'];

        // Create account does not collect an employee ID; one sent anyway is
        // ignored. The Program Leader can add it later from the user directory.

        // Public registration never assigns a role. The account stays pending
        // and inactive until a Program Leader or Finance/Admin approves it.
        $user = User::create([
            'name' => trim($data['first_name'] . ' ' . $data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
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

        // One bell entry (and email) per active Program Leader. A mailer
        // failure is logged and never blocks the registration or the others.
        $programLeaders = User::query()
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($query) => $query->where('slug', 'program-leader'))
            ->get();

        foreach ($programLeaders as $programLeader) {
            try {
                Notification::send($programLeader, new NewRegistrationPendingNotification($user));
            } catch (\Throwable $e) {
                Log::warning('New registration notification could not be sent.', [
                    'applicant_id' => $user->id,
                    'program_leader_id' => $programLeader->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

    /**
     * Step 1 of the password reset: email a 6-digit code. The response is the
     * same whether or not the account exists (no enumeration).
     */
    public function forgotPassword(Request $request, PasswordResetCodeService $codes)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
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

        if (PasswordResetCodeService::isEligible($user)) {
            // Create the code and send the mail after the response has gone
            // out, so active and unknown addresses take the same time. A new
            // code replaces the previous one.
            app()->terminating(function () use ($user, $codes) {
                static $sent = false;

                if ($sent) {
                    return;
                }

                $sent = true;

                try {
                    $user->notify(new PasswordResetCodeNotification($codes->issue($user)));
                } catch (\Throwable $e) {
                    // Never log the code or whether the address exists.
                    Log::error('Password reset code notification failed.');
                }
            });
        }

        return response()->json([
            'message' => self::RESET_CODE_SENT_MESSAGE,
            'code_expires_in' => PasswordResetCodeService::CODE_TTL_MINUTES * 60,
            'resend_in' => 60,
        ], 202);
    }

    /** Step 2: exchange the right code for a short-lived, single-use reset token. */
    public function verifyResetCode(Request $request, PasswordResetCodeService $codes)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $email = strtolower(trim($data['email']));
        $code = preg_replace('/\s+/', '', $data['code']);

        $token = $codes->verify(User::where('email', $email)->first(), $code);

        if ($token === null) {
            throw ValidationException::withMessages([
                'code' => [self::INVALID_CODE_MESSAGE],
            ]);
        }

        return response()->json([
            'reset_token' => $token,
            'expires_in' => PasswordResetCodeService::TOKEN_TTL_MINUTES * 60,
        ]);
    }

    /** Step 3: set the new password with the reset token from step 2. */
    public function resetPassword(Request $request, PasswordResetCodeService $codes)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'reset_token' => ['required', 'string', 'max:128'],
        ]);

        $email = strtolower(trim($data['email']));
        $token = $data['reset_token'];
        $user = User::where('email', $email)->first();

        // Check the token before the password policy so a bad, expired, or
        // already-used token always sends the user back to request a new code.
        if (! $codes->checkToken($user, $token)) {
            throw ValidationException::withMessages([
                'reset_token' => [self::INVALID_RESET_TOKEN_MESSAGE],
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

        $reset = DB::transaction(function () use ($user, $token, $data, $codes) {
            // Spend the token under a row lock so two parallel requests
            // cannot both use it.
            if (! $codes->checkToken($user, $token, true)) {
                return false;
            }

            $user->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ])->save();

            $codes->clear($user);
            $this->revokeUserSessionsAndTokens($user);
            // No code, token or password in the entry.
            AuditTrail::record($user, 'Password reset', $user, [], [], 'Password reset via emailed code');

            return true;
        });

        if (! $reset) {
            throw ValidationException::withMessages([
                'reset_token' => [self::INVALID_RESET_TOKEN_MESSAGE],
            ]);
        }

        try {
            $user->notify(new PasswordChangedNotification());
        } catch (\Throwable $e) {
            Log::error('Password changed notification failed.');
        }

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ], 200);
    }

    public const RESET_CODE_SENT_MESSAGE = 'If an account exists for that address, we sent a 6-digit code to it.';
    public const INVALID_CODE_MESSAGE = 'Invalid or expired code';
    public const INVALID_RESET_TOKEN_MESSAGE = 'Your reset session has expired. Request a new code.';

    private function passwordResetThrottleResponse(int $retryAfter)
    {
        return response()->json([
            'message' => 'Too many reset requests. Please wait before requesting another code.',
            'retry_after' => max(1, $retryAfter),
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
}
