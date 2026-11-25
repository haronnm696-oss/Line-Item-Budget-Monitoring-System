<?php

namespace App\Support;

/** Shared bits of the LIB-branded emails (resources/views/emails/layouts/lib.blade.php). */
class MailBranding
{
    /** The frontend (Vercel) address from FRONTEND_URL, without a trailing slash. */
    public static function frontendUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url', '')), '/');
    }

    /**
     * The LIB wordmark served by the frontend. Mail clients block plain-http
     * images, so it is left out on local http setups (the header shows text).
     */
    public static function logoUrl(): ?string
    {
        $frontendUrl = self::frontendUrl();

        return stripos($frontendUrl, 'https://') === 0
            ? $frontendUrl . '/brand/lib-wordmark-white.png'
            : null;
    }
}
