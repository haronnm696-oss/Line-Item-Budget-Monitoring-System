<?php

namespace App\Support;

/**
 * Percent of a budget used or remaining, and its display label.
 *
 * Values are rounded to one decimal, except when rounding would hide a real
 * amount: a share above zero that rounds to 0.0, or a share just under the
 * whole that rounds to 100.0. Those keep their precision so every screen
 * (and the frontend formatPct) can show "<0.1%" / "99.9%+" instead; CSV
 * exports keep the number itself (csv()).
 * Amounts are decimal(15,2), so differences under half a centavo are float
 * noise and count as zero.
 */
class Percent
{
    private const CENTAVO = 0.005;

    public static function of(float $part, float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        $raw = ($part / $whole) * 100;
        $rounded = round($raw, 1);

        if ($rounded == 0.0 && $part >= self::CENTAVO) {
            return $raw;
        }
        if ($rounded == 100.0 && $part < $whole && ($whole - $part) >= self::CENTAVO) {
            return $raw;
        }

        return $rounded;
    }

    /**
     * A CSV cell: always a plain number so spreadsheets can sum and sort.
     * Keeps the precision of(…) kept (e.g. 0.04), capped at 6 decimals.
     * "<0.1%" / "99.9%+" are for screens and PDFs only.
     */
    public static function csv(float|int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Plain decimal (never 1.0E-5): "42.8", "0.04", "100".
        $text = rtrim(rtrim(number_format(round((float) $value, 6), 6, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
