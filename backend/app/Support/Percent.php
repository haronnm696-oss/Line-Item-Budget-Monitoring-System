<?php

namespace App\Support;

/**
 * Percent of a budget used or remaining, and its display label.
 *
 * Values are rounded to one decimal, except when rounding would hide a real
 * amount: a share above zero that rounds to 0.0, or a share just under the
 * whole that rounds to 100.0. Those keep their precision so every screen
 * (and the frontend formatPct) can show "<0.1%" / "99.9%+" instead.
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

    /** "42.8%", "<0.1%", "99.9%+" (pass $unit '' for a "Spent %" column). */
    public static function label($value, string $unit = '%'): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $n = (float) $value;

        if ($n > 0 && $n < 0.05) {
            return '<0.1' . $unit;
        }
        if ($n >= 99.95 && $n < 100) {
            return '99.9' . $unit . '+';
        }

        return number_format($n, 1, '.', '') . $unit;
    }
}
