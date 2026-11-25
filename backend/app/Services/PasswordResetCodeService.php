<?php

namespace App\Services;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password reset by emailed code.
 *
 * 1. issue():  a new random 6-digit code, valid 10 minutes. It replaces any
 *              earlier code or reset token of the user.
 * 2. verify(): the right code (at most 5 wrong tries) is spent and exchanged
 *              for a single-use reset token, valid 10 minutes.
 * 3. consume(): the reset token is checked and spent; the caller then sets the
 *              new password inside the same transaction.
 *
 * Codes and tokens are stored as HMAC-SHA256 hashes keyed with the app key,
 * so a database leak alone does not reveal or let anyone brute-force them.
 */
class PasswordResetCodeService
{
    public const CODE_LENGTH = 6;
    public const CODE_TTL_MINUTES = 10;
    public const TOKEN_TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;

    public static function isEligible(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            && $user->role_id !== null
            && $user->registration_rejected_at === null;
    }

    /** Creates a new code for the user and returns it (plain, for the email only). */
    public function issue(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        PasswordResetCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => $this->hash($code),
                'attempts' => 0,
                'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'reset_token_hash' => null,
                'reset_token_expires_at' => null,
            ]
        );

        return $code;
    }

    /** The reset token for a right, unexpired code; null otherwise. */
    public function verify(?User $user, string $code): ?string
    {
        if (! self::isEligible($user) || ! preg_match('/^\d{' . self::CODE_LENGTH . '}$/', $code)) {
            return null;
        }

        return DB::transaction(function () use ($user, $code) {
            $row = PasswordResetCode::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $row || $row->code_hash === null || $row->attempts >= self::MAX_ATTEMPTS
                || $row->code_expires_at === null || $row->code_expires_at->isPast()) {
                return null;
            }

            if (! hash_equals($row->code_hash, $this->hash($code))) {
                $attempts = $row->attempts + 1;
                $row->forceFill([
                    'attempts' => $attempts,
                    // The fifth wrong try kills the code; a new one must be requested.
                    'code_hash' => $attempts >= self::MAX_ATTEMPTS ? null : $row->code_hash,
                ])->save();

                return null;
            }

            $token = Str::random(64);
            $row->forceFill([
                'code_hash' => null,
                'code_expires_at' => null,
                'reset_token_hash' => $this->hash($token),
                'reset_token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            ])->save();

            return $token;
        });
    }

    /**
     * Whether the token is valid. With $spend, the row is deleted (single use);
     * call it inside the transaction that changes the password.
     */
    public function checkToken(?User $user, string $token, bool $spend = false): bool
    {
        if (! self::isEligible($user) || $token === '') {
            return false;
        }

        $query = PasswordResetCode::where('user_id', $user->id);
        $row = $spend ? $query->lockForUpdate()->first() : $query->first();

        $valid = $row
            && $row->reset_token_hash !== null
            && $row->reset_token_expires_at !== null
            && ! $row->reset_token_expires_at->isPast()
            && hash_equals($row->reset_token_hash, $this->hash($token));

        if ($valid && $spend) {
            $row->delete();
        }

        return $valid;
    }

    /** Removes every code, reset token and legacy reset link of the user. */
    public function clear(User $user): void
    {
        PasswordResetCode::where('user_id', $user->id)->delete();
        DB::table('password_resets')->where('email', $user->email)->delete();
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
