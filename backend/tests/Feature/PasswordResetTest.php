<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\AuditTrail;
use App\Models\PasswordResetCode;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetCodeNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const SENT = 'If an account exists for that address, we sent a 6-digit code to it.';
    private const INVALID_CODE = 'Invalid or expired code';
    private const NEW_PASSWORD = 'N3w!Secure2026';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activeUser(array $overrides = []): User
    {
        $role = Role::where('slug', 'project-leader')->firstOrFail();

        return User::create(array_merge([
            'name' => 'Maria Santos',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@s.msumain.edu.ph',
            'password' => Hash::make('OldPass!2026'),
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function forgot(string $email)
    {
        $response = $this->postJson('/api/forgot-password', ['email' => $email]);

        // The controller issues the code and sends the mail after the
        // response. The test client does not run the kernel's terminate step,
        // so trigger the registered terminating callbacks explicitly.
        $this->app->terminate();

        return $response;
    }

    /** Requests a code and returns the one that was emailed. */
    private function requestCode(User $user): string
    {
        $this->forgot($user->email)->assertStatus(202);

        $code = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    /** The code sent most recently to the user. */
    private function lastCode(User $user): string
    {
        return Notification::sent($user, PasswordResetCodeNotification::class)->last()->code;
    }

    private function verify(string $email, string $code)
    {
        return $this->postJson('/api/forgot-password/verify', ['email' => $email, 'code' => $code]);
    }

    private function wrongCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function resetToken(User $user): string
    {
        $code = $this->requestCode($user);

        return $this->verify($user->email, $code)->assertOk()->json('reset_token');
    }

    private function reset(string $email, string $token, string $password = self::NEW_PASSWORD, ?string $confirmation = null)
    {
        return $this->postJson('/api/reset-password', [
            'email' => $email,
            'reset_token' => $token,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    public function test_a_code_is_sent_to_an_active_account_and_stored_only_as_a_hash(): void
    {
        $user = $this->activeUser();

        $code = $this->requestCode($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 1);

        $row = PasswordResetCode::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode(DB::table('password_reset_codes')->get()));
        $this->assertSame(0, $row->attempts);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $row->code_expires_at->timestamp, 2);
    }

    public function test_the_response_is_the_same_for_unknown_and_existing_emails(): void
    {
        $user = $this->activeUser();

        $existing = $this->forgot($user->email)
            ->assertStatus(202)
            ->assertJsonPath('message', self::SENT);

        $missing = $this->forgot('nobody@msumain.edu.ph')
            ->assertStatus(202)
            ->assertJsonPath('message', self::SENT);

        $this->assertSame($existing->json(), $missing->json());
        $this->assertArrayNotHasKey('code', $existing->json());
        Notification::assertSentTimes(PasswordResetCodeNotification::class, 1);
        $this->assertSame(1, PasswordResetCode::count());
    }

    public function test_no_code_is_sent_to_inactive_pending_or_rejected_accounts(): void
    {
        $users = [
            $this->activeUser(['email' => 'inactive@msumain.edu.ph', 'is_active' => false]),
            $this->activeUser(['email' => 'pending@msumain.edu.ph', 'role_id' => null, 'is_active' => false]),
            $this->activeUser(['email' => 'rejected@msumain.edu.ph', 'registration_rejected_at' => now()]),
        ];

        foreach ($users as $user) {
            $this->forgot($user->email)->assertStatus(202)->assertJsonPath('message', self::SENT);
        }

        Notification::assertNothingSent();
        $this->assertSame(0, PasswordResetCode::count());
    }

    public function test_the_right_code_returns_a_single_use_reset_token(): void
    {
        $user = $this->activeUser();
        $code = $this->requestCode($user);

        $response = $this->verify($user->email, $code)
            ->assertOk()
            ->assertJsonPath('expires_in', 600);

        $token = $response->json('reset_token');
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(32, strlen($token));

        $row = PasswordResetCode::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($token, $row->reset_token_hash);

        // The code is spent: it cannot be exchanged a second time.
        $this->verify($user->email, $code)
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', self::INVALID_CODE);
    }

    public function test_a_code_with_spaces_from_a_paste_is_accepted(): void
    {
        $user = $this->activeUser();
        $code = $this->requestCode($user);

        $this->verify($user->email, substr($code, 0, 3) . ' ' . substr($code, 3))->assertOk();
    }

    public function test_five_wrong_codes_invalidate_the_code(): void
    {
        $user = $this->activeUser();
        $code = $this->requestCode($user);

        for ($i = 0; $i < 5; $i++) {
            $this->verify($user->email, $this->wrongCode($code))
                ->assertStatus(422)
                ->assertJsonPath('errors.code.0', self::INVALID_CODE);
        }

        $this->verify($user->email, $code)
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', self::INVALID_CODE);
    }

    public function test_four_wrong_codes_still_allow_the_right_one(): void
    {
        $user = $this->activeUser();
        $code = $this->requestCode($user);

        for ($i = 0; $i < 4; $i++) {
            $this->verify($user->email, $this->wrongCode($code))->assertStatus(422);
        }

        $this->verify($user->email, $code)->assertOk();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->activeUser();
        $code = $this->requestCode($user);

        $this->travel(10)->minutes();
        $this->travel(1)->seconds();

        $this->verify($user->email, $code)
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', self::INVALID_CODE);
    }

    public function test_unknown_email_and_malformed_codes_get_the_same_generic_error(): void
    {
        $user = $this->activeUser();
        $this->requestCode($user);

        foreach ([['nobody@msumain.edu.ph', '123456'], [$user->email, 'abcdef'], [$user->email, '12345']] as [$email, $code]) {
            $this->verify($email, $code)
                ->assertStatus(422)
                ->assertJsonPath('errors.code.0', self::INVALID_CODE);
        }
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $user = $this->activeUser();
        $first = $this->requestCode($user);

        $this->travel(61)->seconds();
        $this->forgot($user->email)->assertStatus(202);
        $second = $this->lastCode($user);

        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 2);
        $this->assertSame(1, PasswordResetCode::where('user_id', $user->id)->count());

        if ($first !== $second) {
            $this->verify($user->email, $first)->assertStatus(422);
        }
        $this->verify($user->email, $second)->assertOk();
    }

    public function test_a_new_code_resets_the_wrong_attempt_count(): void
    {
        $user = $this->activeUser();
        $code = $this->requestCode($user);
        for ($i = 0; $i < 5; $i++) {
            $this->verify($user->email, $this->wrongCode($code));
        }
        // Clear the resend cooldown and the per-IP throttle.
        Cache::flush();

        $this->forgot($user->email)->assertStatus(202);
        $this->verify($user->email, $this->lastCode($user))->assertOk();
    }

    public function test_resend_has_a_sixty_second_cooldown(): void
    {
        $user = $this->activeUser();

        $this->forgot($user->email)->assertStatus(202);
        $this->forgot($user->email)->assertStatus(429)->assertHeader('Retry-After');
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 1);

        $this->travel(60)->seconds();

        $this->forgot($user->email)->assertStatus(202);
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 2);
    }

    public function test_each_email_is_limited_to_three_codes_per_hour(): void
    {
        $user = $this->activeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->forgot($user->email)->assertStatus(202);
            $this->travel(61)->seconds();
        }
        $this->forgot($user->email)->assertStatus(429);

        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 3);
    }

    public function test_forgot_password_limits_requests_per_ip(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->forgot('ip.limit.' . $index . '@msumain.edu.ph')->assertStatus(202);
        }

        $this->forgot('ip.limit.5@msumain.edu.ph')->assertStatus(429);
    }

    public function test_verify_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->verify('nobody' . $i . '@msumain.edu.ph', '123456')->assertStatus(422);
        }

        $this->verify('nobody@msumain.edu.ph', '123456')->assertStatus(429);
    }

    public function test_forgot_password_rejects_an_invalid_email_format(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_email_shows_the_code_with_the_lib_logo_and_no_reset_link(): void
    {
        config(['app.frontend_url' => 'https://lib.example.edu']);
        $user = $this->activeUser();

        $code = $this->requestCode($user);

        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) use ($user, $code) {
            $mail = $notification->toMail($user);
            $html = $mail->render();

            return $mail instanceof PasswordResetCodeMail
                && $mail->hasTo($user->email)
                && $mail->subject === 'Your LIB Monitoring System password reset code'
                && $mail->logoUrl === 'https://lib.example.edu/brand/lib-wordmark-white.png'
                && $mail->textView === 'emails.password-reset-code-text'
                && str_contains($html, $code)
                && str_contains($html, 'letter-spacing')
                && str_contains($html, 'alt="LIB Monitoring System"')
                && str_contains($html, 'expires in 10 minutes')
                && str_contains($html, 'If you did not request this, ignore this email.')
                && ! str_contains($html, 'msu-seal')
                && ! str_contains($html, '<a ')
                && ! str_contains($html, 'reset-password');
        });
    }

    public function test_a_reset_token_sets_the_password_once_and_signs_out_everywhere(): void
    {
        $user = $this->activeUser();

        DB::table('sessions')->insert([
            'id' => 'session-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
        $user->createToken('phpunit');
        DB::table('password_resets')->insert(['email' => $user->email, 'token' => 'legacy', 'created_at' => now()]);

        $token = $this->resetToken($user);
        $rememberToken = $user->fresh()->remember_token;

        $response = $this->reset($user->email, $token)
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset. You can now sign in.');
        $this->assertArrayNotHasKey('reset_token', $response->json());

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertSame(0, PasswordResetCode::where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('password_resets', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-1']);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $audit = AuditTrail::where('user_id', $user->id)->where('action', 'Password reset')->firstOrFail();
        $this->assertSame('127.0.0.1', $audit->ip_address);
        $logged = json_encode($audit->toArray());
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $logged);
        $this->assertStringNotContainsString($token, $logged);

        Notification::assertSentTo($user, PasswordChangedNotification::class);

        // Single use.
        $this->reset($user->email, $token, 'An0ther!Secure2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('reset_token');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_an_expired_or_foreign_reset_token_is_refused(): void
    {
        $user = $this->activeUser();
        $other = $this->activeUser(['email' => 'other@msumain.edu.ph']);

        $token = $this->resetToken($user);
        $this->reset($other->email, $token)->assertStatus(422)->assertJsonValidationErrors('reset_token');
        $this->reset('nobody@msumain.edu.ph', $token)->assertStatus(422)->assertJsonValidationErrors('reset_token');
        $this->reset($user->email, 'not-a-real-token')->assertStatus(422)->assertJsonValidationErrors('reset_token');

        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $this->reset($user->email, $token)->assertStatus(422)->assertJsonValidationErrors('reset_token');
        $this->assertTrue(Hash::check('OldPass!2026', $user->fresh()->password));
    }

    public function test_reset_requires_the_same_strong_password_rules_as_registration(): void
    {
        $user = $this->activeUser();
        $token = $this->resetToken($user);

        $cases = [
            ['short', 'short'],
            ['NoNumbers!', 'NoNumbers!'],
            ['NoSymbols2026', 'NoSymbols2026'],
            ['Maria!2026', 'Maria!2026'],
            ['maria.santos!2026', 'maria.santos!2026'],
            [self::NEW_PASSWORD, 'Different!2026'],
        ];

        foreach ($cases as [$password, $confirmation]) {
            $this->reset($user->email, $token, $password, $confirmation)
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }

        // A refused password does not spend the token.
        $this->reset($user->email, $token)->assertOk();
    }

    public function test_reset_rejects_an_invalid_email_format(): void
    {
        $this->reset('not-an-email', 'whatever')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_link_based_reset_is_gone(): void
    {
        $user = $this->activeUser();

        // The old payload (token from an emailed link) is refused.
        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'link-token',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('reset_token');

        $this->assertFalse(class_exists(\App\Notifications\ResetPasswordNotification::class));
        $this->assertFalse(view()->exists('emails.reset-password'));
        $this->assertTrue(Schema::hasTable('password_reset_codes'));
    }

    public function test_any_valid_email_domain_can_reset(): void
    {
        $user = $this->activeUser(['email' => 'maria.santos@yahoo.com']);

        $this->reset($user->email, $this->resetToken($user))->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }
}
