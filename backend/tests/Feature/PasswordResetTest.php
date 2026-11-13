<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

        // The controller schedules token creation and mail delivery after the
        // response. The test client does not run the kernel's terminate step,
        // so trigger the registered terminating callbacks explicitly.
        $this->app->terminate();

        return $response;
    }

    public function test_forgot_password_returns_the_same_response_for_existing_and_missing_accounts(): void
    {
        Notification::fake();
        $user = $this->activeUser();

        $existing = $this->forgot($user->email)
            ->assertStatus(202)
            ->assertJsonPath('message', 'If an account exists for that address, a password reset link has been sent.');

        $missing = $this->forgot('nobody@msumain.edu.ph')
            ->assertStatus(202)
            ->assertJsonPath('message', 'If an account exists for that address, a password reset link has been sent.');

        $this->assertSame($existing->json(), $missing->json());
        $this->assertArrayNotHasKey('token', $existing->json());
        $this->assertArrayNotHasKey('password', $existing->json());

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_forgot_password_does_not_send_to_inactive_pending_or_rejected_accounts(): void
    {
        Notification::fake();

        $inactive = $this->activeUser([
            'email' => 'inactive@msumain.edu.ph',
            'is_active' => false,
        ]);

        $pending = $this->activeUser([
            'email' => 'pending@msumain.edu.ph',
            'role_id' => null,
            'is_active' => false,
        ]);

        $rejected = $this->activeUser([
            'email' => 'rejected@msumain.edu.ph',
            'registration_rejected_at' => now(),
        ]);

        foreach ([$inactive, $pending, $rejected] as $user) {
            $this->forgot($user->email)->assertStatus(202);
        }

        Notification::assertNothingSent();
    }

    public function test_forgot_password_enforces_a_sixty_second_resend_cooldown(): void
    {
        Notification::fake();
        $user = $this->activeUser();

        $this->forgot($user->email)->assertStatus(202);
        $this->forgot($user->email)->assertStatus(429);
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);

        $this->travel(60)->seconds();

        $this->forgot($user->email)->assertStatus(202);
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 2);
    }

    public function test_forgot_password_limits_each_email_to_three_requests_per_hour(): void
    {
        Notification::fake();
        $user = $this->activeUser();

        $this->forgot($user->email)->assertStatus(202);
        $this->travel(61)->seconds();
        $this->forgot($user->email)->assertStatus(202);
        $this->travel(61)->seconds();
        $this->forgot($user->email)->assertStatus(202);
        $this->travel(61)->seconds();
        $this->forgot($user->email)->assertStatus(429);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 3);
    }

    public function test_forgot_password_limits_requests_per_ip(): void
    {
        Notification::fake();

        for ($index = 0; $index < 5; $index++) {
            $this->forgot('ip.limit.' . $index . '@msumain.edu.ph')->assertStatus(202);
        }

        $this->forgot('ip.limit.5@msumain.edu.ph')->assertStatus(429);
        Notification::assertNothingSent();
    }

    public function test_forgot_password_rejects_an_invalid_email_format(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_reset_email_uses_the_frontend_url_and_branded_content(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://lib.example.edu']);
        $user = $this->activeUser();

        $this->forgot($user->email)->assertStatus(202);

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($user) {
                $mail = $notification->toMail($user);
                $html = $mail->render();

                return $mail instanceof ResetPasswordMail
                    && $mail->hasTo($user->email)
                    && strpos($mail->resetUrl, 'https://lib.example.edu/reset-password?token=') === 0
                    && strpos($mail->resetUrl, 'email=' . urlencode($user->email)) !== false
                    && $mail->logoUrl === 'https://lib.example.edu/images/msu-seal-outline.png'
                    && $mail->subject === 'Reset your LIB Monitoring System password'
                    && $mail->textView === 'emails.reset-password-text'
                    && strpos($html, 'Reset password') !== false
                    && strpos($html, '60 minutes') !== false;
            }
        );
    }

    public function test_a_valid_token_resets_the_password_and_revokes_sessions(): void
    {
        Notification::fake();
        $user = $this->activeUser();
        $token = Password::createToken($user);
        $newPassword = 'N3w!Secure2026';

        DB::table('sessions')->insert([
            'id' => 'session-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $user->createToken('phpunit');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset. You can now sign in.');

        $payload = $response->json();
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('password', $payload);

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertDatabaseMissing('password_resets', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-1']);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $audit = AuditTrail::where('user_id', $user->id)
            ->where('action', 'Password reset')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('127.0.0.1', $audit->ip_address);
        $this->assertStringNotContainsString($newPassword, json_encode($audit->toArray()));
        $this->assertStringNotContainsString($token, json_encode($audit->toArray()));

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_expired_used_and_malformed_tokens_return_422(): void
    {
        $user = $this->activeUser();
        $password = 'N3w!Secure2026';

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'not-a-real-token',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $expiredToken = Password::createToken($user);
        $this->travel(61)->minutes();

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $expiredToken,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $usedToken = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $usedToken,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $usedToken,
            'password' => 'An0ther!Secure2026',
            'password_confirmation' => 'An0ther!Secure2026',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_reset_password_requires_the_same_strong_password_rules_as_registration(): void
    {
        $user = $this->activeUser();
        $token = Password::createToken($user);

        $cases = [
            ['password' => 'short', 'password_confirmation' => 'short'],
            ['password' => 'NoNumbers!', 'password_confirmation' => 'NoNumbers!'],
            ['password' => 'NoSymbols2026', 'password_confirmation' => 'NoSymbols2026'],
            ['password' => 'Maria!2026', 'password_confirmation' => 'Maria!2026'],
            ['password' => 'maria.santos!2026', 'password_confirmation' => 'maria.santos!2026'],
            ['password' => 'N3w!Secure2026', 'password_confirmation' => 'Different!2026'],
        ];

        foreach ($cases as $case) {
            $this->postJson('/api/reset-password', [
                'email' => $user->email,
                'token' => $token,
                'password' => $case['password'],
                'password_confirmation' => $case['password_confirmation'],
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'N3w!Secure2026',
            'password_confirmation' => 'N3w!Secure2026',
        ])->assertOk();
    }

    public function test_reset_password_rejects_an_invalid_email_format(): void
    {
        $this->postJson('/api/reset-password', [
            'email' => 'not-an-email',
            'token' => 'whatever',
            'password' => 'N3w!Secure2026',
            'password_confirmation' => 'N3w!Secure2026',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_reset_password_does_not_reveal_whether_an_email_exists(): void
    {
        $this->postJson('/api/reset-password', [
            'email' => 'nobody@msumain.edu.ph',
            'token' => 'not-a-real-token',
            'password' => 'N3w!Secure2026',
            'password_confirmation' => 'N3w!Secure2026',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }
}
