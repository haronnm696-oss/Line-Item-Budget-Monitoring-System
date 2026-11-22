<?php

namespace App\Support;

/**
 * Peso amounts as people type them: "3,178,376.00", "₱ 2,000,000",
 * "2 000 000". A leading ₱, and commas or spaces between groups of three
 * digits, are stripped before validation (the NormalizeMoneyInput
 * middleware runs this on every API request), so the API accepts what the
 * screens accept (frontend lib/moneyInput). Text that is not a well-formed
 * amount ("1,23.456", "abc") is left as sent, and validation rejects it.
 */
final class MoneyInput
{
    /**
     * Request fields that hold peso amounts. Only these are normalised, so
     * other text (descriptions, references, account numbers) is never touched.
     */
    public const FIELDS = [
        'amount', 'total_approved_budget', 'funding_agency_amount', 'implementing_agency_counterpart',
        'implementing_agency_amount', 'funder_amount', 'allocated_amount', 'rate',
        'estimated_amount', 'basic_rate', 'monthly_honorarium', 'unit_cost',
    ];

    /** Validation for an amount: a number, zero or more, at most two decimals. */
    public const RULES = ['numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];

    public const DECIMALS_MESSAGE = 'Enter a number with at most two decimals.';

    /** "3,178,376.00" -> "3178376.00"; anything else is returned unchanged. */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $text = trim(preg_replace('/^\s*₱\s*/u', '', $value));
        if (preg_match('/^\d+(\.\d+)?$/', $text)) {
            return $text;
        }
        if (preg_match('/^\d{1,3}(?:[, ]\d{3})+(\.\d+)?$/', $text)) {
            return str_replace([',', ' '], '', $text);
        }

        return $value;
    }

    /**
     * The FIELDS in $input normalised, at any depth (so "items.*.amount" is
     * covered too).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::normalizeInput($value);
            } elseif (is_string($key) && in_array($key, self::FIELDS, true)) {
                $input[$key] = self::normalize($value);
            }
        }

        return $input;
    }
}
