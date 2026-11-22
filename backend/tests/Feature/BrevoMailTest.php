<?php

namespace Tests\Feature;

use App\Mail\Transport\BrevoTransport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MAIL_MAILER=brevo sends every Mailable and mail notification through
 * Brevo's HTTPS API (Render's free tier blocks SMTP). The HTTP client is
 * faked, so nothing leaves the test.
 */
class BrevoMailTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'xkeysib-test-secret-key-0123456789';

    /** @var array<int, array{level: string, message: string, context: array}> */
    private $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'mail.default' => 'brevo',
            'mail.mailers.brevo.key' => self::KEY,
            'mail.from.address' => 'no-reply@msumain.edu.ph',
            'mail.from.name' => 'LIB Monitoring System',
            'app.frontend_url' => 'https://lib.example.test',
        ]);
        $this->app['mail.manager']->forgetMailers();

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    public function test_a_password_reset_sends_one_brevo_call_with_recipient_sender_and_link(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['messageId' => '<abc@brevo>'], 201)]);
        $user = $this->user('project-leader', 'maria.santos@s.msumain.edu.ph');

        $this->forgot($user->email)->assertStatus(202);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($user) {
            $body = $request->data();
            $html = $body['htmlContent'] ?? '';

            return $request->url() === BrevoTransport::ENDPOINT
                && $request->method() === 'POST'
                && $request->hasHeader('api-key', self::KEY)
                && $body['to'][0]['email'] === $user->email
                && count($body['to']) === 1
                && $body['sender'] === ['email' => 'no-reply@msumain.edu.ph', 'name' => 'LIB Monitoring System']
                && $body['subject'] !== ''
                && str_contains($html, 'https://lib.example.test/reset-password?token=')
                && str_contains($html, urlencode($user->email))
                && str_contains($body['textContent'] ?? '', 'https://lib.example.test/reset-password?token=')
                && str_contains($body['textContent'], '&email=' . urlencode($user->email));
        });
    }

    public function test_registration_approval_sends_one_brevo_call(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['messageId' => '<abc@brevo>'], 201)]);
        $leader = $this->user('program-leader', 'leader@msumain.edu.ph');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'research-assistant',
            ])
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->data()['to'][0]['email'] === $pending->email);
    }

    public function test_a_provider_error_is_logged_and_forgot_password_keeps_its_neutral_message(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response([
            'code' => 'unauthorized',
            'message' => 'Sender not verified',
        ], 400)]);
        $user = $this->user('project-leader', 'maria.santos@s.msumain.edu.ph');

        $this->forgot($user->email)
            ->assertStatus(202)
            ->assertJsonPath('message', 'If an account exists for that address, a password reset link has been sent.');

        $entry = collect($this->logs)->firstWhere('message', 'Brevo rejected the mail.');
        $this->assertNotNull($entry, 'The provider error was not logged.');
        $this->assertSame('error', $entry['level']);
        $this->assertSame(400, $entry['context']['status']);
        $this->assertStringContainsString('Sender not verified', $entry['context']['response']);

        $this->assertKeyNeverLogged();
    }

    public function test_a_provider_error_never_blocks_an_approval_or_add_user(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['message' => 'boom'], 500)]);
        $leader = $this->user('program-leader', 'leader@msumain.edu.ph');
        $pending = $this->pendingUser();

        $this->actingAs($leader)
            ->postJson("/api/program/registrations/{$pending->id}/decision", [
                'decision' => 'approved',
                'role' => 'research-assistant',
            ])
            ->assertOk();
        $this->assertTrue($pending->fresh()->is_active);

        $this->actingAs($leader)
            ->postJson('/api/program/users', [
                'first_name' => 'New',
                'last_name' => 'Leader',
                'email' => 'new.leader@msumain.edu.ph',
                'employee_id' => 'MSU-54321',
                'position' => 'Assistant Professor',
                'college' => 'College of Information and Computing Sciences',
                'department' => 'Computing Sciences',
                'role' => 'project-leader',
            ])
            ->assertCreated()
            ->assertJsonPath('setup_email_sent', false);
        $this->assertDatabaseHas('users', ['email' => 'new.leader@msumain.edu.ph']);

        $this->assertKeyNeverLogged();
    }

    public function test_a_connection_failure_is_logged_without_the_key(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28 api-key: ' . self::KEY);
        });
        $user = $this->user('project-leader', 'maria.santos@s.msumain.edu.ph');

        $this->forgot($user->email)->assertStatus(202);

        $this->assertNotNull(collect($this->logs)->firstWhere('message', 'Brevo mail request failed.'));
        $this->assertKeyNeverLogged();
    }

    private function assertKeyNeverLogged(): void
    {
        $this->assertNotEmpty($this->logs);
        $this->assertStringNotContainsString(self::KEY, json_encode($this->logs));
    }

    private function forgot(string $email)
    {
        $response = $this->postJson('/api/forgot-password', ['email' => $email]);
        // The reset mail is sent in a terminating callback; run it now.
        $this->app->terminate();

        return $response;
    }

    private function user(string $slug, string $email): User
    {
        return User::create([
            'name' => 'Maria Santos',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => $email,
            'employee_id' => 'MSU-' . random_int(10000, 99999),
            'password' => Hash::make('OldPass!2026'),
            'position' => 'Staff',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function pendingUser(): User
    {
        return User::create([
            'name' => 'Pending Person',
            'first_name' => 'Pending',
            'last_name' => 'Person',
            'email' => 'pending.person@msumain.edu.ph',
            'employee_id' => 'MSU-' . random_int(10000, 99999),
            'password' => Hash::make('password123'),
            'position' => 'Instructor',
            'college' => 'College of Natural Sciences and Mathematics',
            'department' => 'Computer Science',
            'role_id' => null,
            'is_active' => false,
            'email_verified_at' => now(),
        ]);
    }
}
