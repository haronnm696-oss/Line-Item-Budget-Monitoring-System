<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profile photos. They go to the same backend as documents
 * (config('documents.disk'), DOCUMENTS_DISK):
 *   "database"  bytes in avatar_contents (production; survives redeploys)
 *   any other   that Laravel filesystem disk, at avatars/<user id>/<name>.jpg
 * Every photo is re-encoded as a 256x256 JPEG, so only image bytes the
 * server produced are ever served.
 */
class AvatarStorage
{
    public const SIZE = 256;
    public const MIME = 'image/jpeg';

    public function disk(): string
    {
        return app(DocumentStorage::class)->defaultDisk();
    }

    public static function canProcessImages(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /**
     * Center-crop to a square and scale to 256x256 JPEG. Throws when the
     * bytes are not a readable image.
     */
    public function squareJpeg(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('The file is not a readable image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $x = intdiv($width - $side, 2);
        $y = intdiv($height - $side, 2);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        // Transparent PNG / WebP areas become white instead of black.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($target, null, 85);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $jpeg;
    }

    /** Store a new photo for the user, then remove the old one. Returns the new path. */
    public function replace(User $user, string $jpeg): string
    {
        $old = $user->avatar_path;
        $path = 'avatars/' . $user->id . '/' . Str::random(20) . '.jpg';
        $disk = $this->disk();

        if ($disk === DocumentStorage::DATABASE) {
            // One row per user: the update replaces the old bytes.
            DB::table('avatar_contents')->updateOrInsert(
                ['user_id' => $user->id],
                ['path' => $path, 'mime' => self::MIME, 'content' => $jpeg, 'size' => strlen($jpeg), 'created_at' => now()]
            );
        } else {
            Storage::disk($disk)->put($path, $jpeg);
        }

        $user->forceFill(['avatar_path' => $path])->save();

        if ($old && $old !== $path && $disk !== DocumentStorage::DATABASE) {
            Storage::disk($disk)->delete($old);
        }

        return $path;
    }

    /** Remove the user's photo (bytes and path). A file that is already gone is fine. */
    public function remove(User $user): void
    {
        $old = $user->avatar_path;
        DB::table('avatar_contents')->where('user_id', $user->id)->delete();
        if ($old && $this->disk() !== DocumentStorage::DATABASE) {
            Storage::disk($this->disk())->delete($old);
        }

        $user->forceFill(['avatar_path' => null])->save();
    }

    /** The JPEG bytes of the user's photo, or null. */
    public function contents(User $user): ?string
    {
        if (! $user->avatar_path) {
            return null;
        }

        $row = DB::table('avatar_contents')
            ->where('user_id', $user->id)
            ->where('path', $user->avatar_path)
            ->value('content');
        if ($row !== null) {
            return is_resource($row) ? stream_get_contents($row) : (string) $row;
        }

        $disk = $this->disk();
        if ($disk === DocumentStorage::DATABASE) {
            return null;
        }

        $storage = Storage::disk($disk);

        return $storage->exists($user->avatar_path) ? $storage->get($user->avatar_path) : null;
    }

    /** Relative URL the frontend loads (same origin as the API), or null. */
    public static function url(User $user): ?string
    {
        if (! $user->avatar_path) {
            return null;
        }

        // The path changes on every upload, so the version busts browser caches.
        return '/api/users/' . $user->id . '/avatar?v=' . substr(sha1($user->avatar_path), 0, 12);
    }
}
