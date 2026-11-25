<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\User;
use App\Services\AvatarStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Profile photo of the signed-in user. Upload and remove act only on the
 * caller's own account (there is no user id in those routes); any signed-in
 * user may view anyone's photo.
 */
class ProfileAvatarController extends Controller
{
    public const MAX_KB = 2048;

    /** POST /api/profile/avatar (multipart "avatar": JPG, PNG or WebP, at most 2 MB). */
    public function store(Request $request, AvatarStorage $avatars)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . self::MAX_KB],
        ], [
            'avatar.required' => 'Choose a photo to upload.',
            'avatar.file' => 'Choose a photo to upload.',
            'avatar.uploaded' => 'The photo must be 2 MB or smaller.',
            'avatar.mimes' => 'Choose a JPG, PNG or WebP image.',
            'avatar.max' => 'The photo must be 2 MB or smaller.',
        ]);

        if (! AvatarStorage::canProcessImages()) {
            return response()->json(['message' => 'Photo uploads are not available on the server right now.'], 503);
        }

        try {
            $jpeg = $avatars->squareJpeg((string) file_get_contents($request->file('avatar')->getRealPath()));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'Choose a JPG, PNG or WebP image.',
                'errors' => ['avatar' => ['Choose a JPG, PNG or WebP image.']],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $hadPhoto = (bool) $user->avatar_path;

        DB::transaction(function () use ($avatars, $user, $jpeg, $hadPhoto) {
            $avatars->replace($user, $jpeg);
            AuditTrail::record($user, 'Profile photo updated', $user, ['avatar' => $hadPhoto ? 'photo' : 'none'], ['avatar' => 'photo']);
        });

        return response()->json(['user' => AuthController::toUserPayload($user->fresh())]);
    }

    /** DELETE /api/profile/avatar */
    public function destroy(Request $request, AvatarStorage $avatars)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path) {
            DB::transaction(function () use ($avatars, $user) {
                $avatars->remove($user);
                AuditTrail::record($user, 'Profile photo removed', $user, ['avatar' => 'photo'], ['avatar' => 'none']);
            });
        }

        return response()->json(['user' => AuthController::toUserPayload($user->fresh())]);
    }

    /** GET /api/users/{user}/avatar — the 256x256 JPEG (any signed-in user). */
    public function show(User $user, AvatarStorage $avatars)
    {
        $contents = $avatars->contents($user);
        if ($contents === null) {
            return response()->json(['message' => 'No profile photo.'], 404);
        }

        return response($contents, 200, [
            'Content-Type' => AvatarStorage::MIME,
            'Content-Disposition' => 'inline; filename="avatar.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            // The URL carries a version, so the browser may keep it for a while.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
