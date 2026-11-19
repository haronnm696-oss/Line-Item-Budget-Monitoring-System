<?php

namespace App\Support;

/**
 * The optional Form B computation of a line item. It gives the FUNDER
 * amount; the implementing agency counterpart is entered separately and
 * added to it (allocated = counterpart + funder):
 *
 *   quantity x rate [per month|quarter] x periods = amount
 *   1 x 8,800/mo x 12 = 105,600
 *   "One (1) Project Leader @ ₱8,800.00/mo. x 12 mos."
 *
 * A computation is present when quantity and rate are both filled; periods
 * defaults to 1 and the rate period is optional. Mirrored on the frontend in
 * src/lib/lineItemComputation.js.
 */
class LineItemComputation
{
    public const PERIODS = ['month', 'quarter'];

    /** The stored computation columns on lib_categories. */
    public const FIELDS = ['quantity', 'unit_description', 'rate', 'rate_period', 'periods'];

    private const PERIOD_WORDS = [
        'month' => ['/mo.', 'mo.', 'mos.'],
        'quarter' => ['/qtr.', 'qtr.', 'qtrs.'],
    ];

    /** True when the row carries a computation (quantity and rate). */
    public static function present(array $row): bool
    {
        return self::filled($row['quantity'] ?? null) && self::filled($row['rate'] ?? null);
    }

    /** quantity x rate x periods, or null without a computation. */
    public static function amount(array $row): ?float
    {
        if (! self::present($row)) {
            return null;
        }
        $periods = self::filled($row['periods'] ?? null) ? (int) $row['periods'] : 1;

        return round((int) $row['quantity'] * (float) $row['rate'] * $periods, 2);
    }

    /** "One (1) Project Leader @ ₱8,800.00/mo. x 12 mos.", or null. */
    public static function describe(array $row): ?string
    {
        if (! self::present($row)) {
            return null;
        }
        $quantity = (int) $row['quantity'];
        $unit = trim((string) ($row['unit_description'] ?? ''));
        $period = $row['rate_period'] ?? null;
        $words = self::PERIOD_WORDS[$period] ?? ['', '', ''];
        $text = ucfirst(self::words($quantity)) . " ({$quantity})" . ($unit !== '' ? " {$unit}" : '')
            . ' @ ₱' . number_format((float) $row['rate'], 2) . $words[0];

        if (self::filled($row['periods'] ?? null)) {
            $periods = (int) $row['periods'];
            $unitWord = $period ? ' ' . ($periods === 1 ? $words[1] : $words[2]) : '';
            $text .= " x {$periods}{$unitWord}";
        }

        return $text;
    }

    /** English words for a whole number (0 – 999,999,999). */
    public static function words(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        }
        if ($n < 1000) {
            return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . self::words($n % 100) : '');
        }
        foreach ([1000000 => 'million', 1000 => 'thousand'] as $size => $name) {
            if ($n >= $size) {
                return self::words(intdiv($n, $size)) . " {$name}" . ($n % $size ? ' ' . self::words($n % $size) : '');
            }
        }

        return (string) $n;
    }

    private static function filled($value): bool
    {
        return $value !== null && $value !== '';
    }
}
