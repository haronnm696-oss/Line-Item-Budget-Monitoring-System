<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Dates (YYYY-MM-DD) and months (YYYY-MM) must fall in 2000–2100, the same
 * min/max every date input carries (frontend lib/dateRange). Browsers let
 * people type six-digit years such as 222222-12-15 otherwise.
 *
 * Put it first, after 'bail', so an out-of-range year reads "Enter a date
 * between 2000 and 2100." instead of "not a valid date". A value that does
 * not start with a year is left to the other rules ('date', 'date_format').
 */
class DateInRange implements Rule
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;
    public const MESSAGE = 'Enter a date between 2000 and 2100.';

    public function passes($attribute, $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\s*(-?\d+)-\d{1,2}/', $value, $match)) {
            return true;
        }
        $year = (int) $match[1];

        return strlen(ltrim($match[1], '-')) === 4 && $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    public function message(): string
    {
        return self::MESSAGE;
    }

    /** ['bail', new DateInRange] to put in front of a date field's rules. */
    public static function first(): array
    {
        return ['bail', new self()];
    }
}
