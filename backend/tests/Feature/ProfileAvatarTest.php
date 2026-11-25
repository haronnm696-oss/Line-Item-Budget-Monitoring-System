<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Services\AvatarStorage;
use App\Services\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Profile photo: POST/DELETE /api/profile/avatar (own account only),
 * GET /api/users/{id}/avatar (any signed-in user), avatar_url in payloads.
 */
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['documents.disk' => DocumentStorage::DATABASE]);
    }

    private function requireGd(): void
    {
        if (! AvatarStorage::canProcessImages()) {
            $this->markTestSkipped('The GD extension is needed to resize photos (run with php -d extension=gd).');
        }
    }

    private function upload($user, $file)
    {
        return $this->actingAs($user)->post('/api/profile/avatar', ['avatar' => $file], ['Accept' => 'application/json']);
    }

    public function test_a_valid_image_is_cropped_to_256_square_and_stored_in_the_database(): void
    {
        $this->requireGd();
        $user = $this->assistant;

        $response = $this->upload($user, UploadedFile::fake()->image('me.png', 800, 500))->assertOk();

        $user->refresh();
        $this->assertMatchesRegularExpression('#^avatars/' . $user->id . '/[A-Za-z0-9]+\.jpg$#', $user->avatar_path);
        $this->assertSame(AvatarStorage::url($user), $response->json('user.avatar_url'));
        $this->assertStringStartsWith('/api/users/' . $user->id . '/avatar?v=', $response->json('user.avatar_url'));

        $bytes = app(AvatarStorage::class)->contents($user);
        [$width, $height, $type] = getimagesizefromstring($bytes);
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$width, $height, $type]);

        $this->assertTrue(AuditTrail::where('action', 'Profile photo updated')->where('user_id', $user->id)->where('model_id', $user->id)->exists());
    }

    public function test_jpg_and_webp_are_accepted(): void
    {
        $this->requireGd();
        $this->upload($this->assistant, UploadedFile::fake()->image('me.jpg', 300, 300))->assertOk();

        if (function_exists('imagewebp')) {
            $this->upload($this->assistant, UploadedFile::fake()->image('me.webp', 300, 400))->assertOk();
        }
    }

    public function test_a_new_photo_replaces_the_old_one_on_a_filesystem_disk(): void
    {
        $this->requireGd();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = $this->assistant;

        $this->upload($user, UploadedFile::fake()->image('a.png', 300, 300))->assertOk();
        $first = $user->refresh()->avatar_path;
        Storage::disk('local')->assertExists($first);

        $this->upload($user, UploadedFile::fake()->image('b.png', 300, 300))->assertOk();
        $second = $user->refresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->assertCount(1, Storage::disk('local')->files('avatars/' . $user->id));
    }

    public function test_a_new_photo_replaces_the_old_bytes_in_the_database(): void
    {
        $this->requireGd();
        $user = $this->assistant;
        $this->upload($user, UploadedFile::fake()->image('a.png', 300, 300))->assertOk();
        $this->upload($user, UploadedFile::fake()->image('b.png', 300, 300))->assertOk();

        $this->assertSame(1, DB::table('avatar_contents')->where('user_id', $user->id)->count());
        $this->assertSame($user->refresh()->avatar_path, DB::table('avatar_contents')->where('user_id', $user->id)->value('path'));
    }

    public function test_files_over_2_mb_are_refused(): void
    {
        // A JPEG-typed fake (no GD needed) reported as just over 2 MB.
        $file = UploadedFile::fake()->createWithContent('big.jpg', "\xFF\xD8\xFF\xE0 jpeg")->mimeType('image/jpeg')->size(2049);

        $this->upload($this->assistant, $file)
            ->assertStatus(422)
            ->assertJsonPath('errors.avatar.0', 'The photo must be 2 MB or smaller.');
        $this->assertNull($this->assistant->refresh()->avatar_path);
    }

    public function test_non_images_are_refused(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF")->mimeType('application/pdf');
        $this->upload($this->assistant, $pdf)
            ->assertStatus(422)
            ->assertJsonPath('errors.avatar.0', 'Choose a JPG, PNG or WebP image.');

        $this->assertNull($this->assistant->refresh()->avatar_path);
    }

    public function test_text_renamed_to_png_is_refused(): void
    {
        $this->requireGd();
        $fake = UploadedFile::fake()->createWithContent('photo.png', 'not really an image');

        $this->upload($this->assistant, $fake)
            ->assertStatus(422)
            ->assertJsonPath('errors.avatar.0', 'Choose a JPG, PNG or WebP image.');
        $this->assertNull($this->assistant->refresh()->avatar_path);
        $this->assertSame(0, DB::table('avatar_contents')->count());
    }

    public function test_guests_cannot_upload_view_or_remove(): void
    {
        $this->postJson('/api/profile/avatar', [])->assertUnauthorized();
        $this->deleteJson('/api/profile/avatar')->assertUnauthorized();
        $this->getJson('/api/users/' . $this->assistant->id . '/avatar')->assertUnauthorized();
    }

    public function test_only_the_owner_changes_their_photo(): void
    {
        $this->requireGd();
        $owner = $this->assistant;
        $this->upload($owner, UploadedFile::fake()->image('me.png', 300, 300))->assertOk();
        $ownerPath = $owner->refresh()->avatar_path;

        // Another user's upload and delete act on their own account, never the owner's.
        $other = $this->projectLeader;
        $this->upload($other, UploadedFile::fake()->image('them.png', 300, 300))->assertOk();
        $this->actingAs($other)->deleteJson('/api/profile/avatar')->assertOk()->assertJsonPath('user.id', $other->id);

        $this->assertSame($ownerPath, $owner->refresh()->avatar_path);
        $this->assertNull($other->refresh()->avatar_path);
        $this->assertNotNull(app(AvatarStorage::class)->contents($owner));

        // There is no route that names another user's photo for writing.
        $this->actingAs($other)->postJson('/api/users/' . $owner->id . '/avatar', [])->assertStatus(405);
        $this->actingAs($other)->deleteJson('/api/users/' . $owner->id . '/avatar')->assertStatus(405);
    }

    public function test_any_signed_in_user_can_view_a_photo(): void
    {
        $this->requireGd();
        $owner = $this->assistant;
        $this->upload($owner, UploadedFile::fake()->image('me.png', 300, 300))->assertOk();

        // The outsider is on another project, and can still see the photo.
        $response = $this->actingAs($this->outsider)->get(AvatarStorage::url($owner->refresh()))->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->actingAs($this->outsider)->getJson('/api/users/' . $this->projectLeader->id . '/avatar')->assertNotFound();
    }

    public function test_delete_removes_the_photo_and_its_bytes(): void
    {
        $this->requireGd();
        $user = $this->assistant;
        $this->upload($user, UploadedFile::fake()->image('me.png', 300, 300))->assertOk();

        $this->actingAs($user)->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertSame(0, DB::table('avatar_contents')->where('user_id', $user->id)->count());
        $this->actingAs($user)->getJson('/api/users/' . $user->id . '/avatar')->assertNotFound();
        $this->assertTrue(AuditTrail::where('action', 'Profile photo removed')->where('user_id', $user->id)->exists());
    }

    public function test_delete_on_a_filesystem_disk_removes_the_file(): void
    {
        $this->requireGd();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = $this->assistant;
        $this->upload($user, UploadedFile::fake()->image('me.png', 300, 300))->assertOk();
        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->deleteJson('/api/profile/avatar')->assertOk();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_avatar_url_is_in_the_current_user_and_directory_payloads(): void
    {
        $user = $this->assistant;
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('user.avatar_url', null);

        $user->forceFill(['avatar_path' => 'avatars/' . $user->id . '/abc.jpg'])->save();
        $expected = AvatarStorage::url($user);
        $this->actingAs($user)->getJson('/api/me')->assertJsonPath('user.avatar_url', $expected);

        $rows = collect($this->actingAs($this->programLeader)->getJson('/api/program/users')->assertOk()->json('users') ?? []);
        $row = $rows->firstWhere('id', $user->id);
        $this->assertNotNull($row, 'The user is listed in the directory.');
        $this->assertSame($expected, $row['avatar_url']);
    }
}
