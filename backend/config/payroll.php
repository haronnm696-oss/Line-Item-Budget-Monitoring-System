<?php

/*
|--------------------------------------------------------------------------
| Payroll deduction rules
|--------------------------------------------------------------------------
|
| Government deductions applied by "Compute payroll", in this order. Every
| rate, bracket table, employee share and salary floor / ceiling comes from
| an environment variable (see backend/.env.example). NOTHING IS ASSUMED:
| an empty or invalid variable is null. The official values must come from
| the MSU accounting office; none is committed to the repository.
|
| A rule that is not fully set deducts 0.00 and is listed under
| `unset_rules`, so the Payroll page shows "Deduction rates are not set yet".
|   percent rule: set when both its RATE and its EMPLOYEE_SHARE are given
|   table rule:   set when its BRACKETS hold at least one valid bracket
| BASE_MIN / BASE_MAX are optional: empty = no salary floor / ceiling.
|
| Changes need a config cache clear (`php artisan config:clear`); on Render
| the entrypoint caches the config, so set the variables and redeploy.
| Computed batches do not change by themselves: "Recompute" a draft or
| returned batch after the rates change.
|
| Gross pay = monthly rate + allowance.
|
| Each rule:
|   type            Name shown on the payroll and stored per deduction row.
|   method          'percent' | 'table'
|   base            What a percent / table applies to:
|                     'basic'   = monthly rate
|                     'gross'   = monthly rate + allowance
|                     'taxable' = gross minus the rules above marked pre_tax
|   rate            percent: e.g. 5 for 5%
|   employee_share  percent: share paid by the employee, 0..1
|   base_min        percent: the base is raised to at least this (salary floor)
|   base_max        percent: the base is capped at this (salary ceiling)
|   max             cap on the deduction amount (not used: null)
|   brackets        table: list of {"over": X, "base_tax": Y, "rate": Z}
|                   meaning: base_tax + Z% of the part of the base above X,
|                   using the highest bracket whose "over" the base exceeds
|   pre_tax         true when the deduction is subtracted before tax
|
*/

/** A number from the environment, or null when empty or not a number. */
$number = function (string $key): ?float {
    $value = env($key);
    if (is_string($value)) {
        $value = trim($value);
    }

    return is_numeric($value) ? (float) $value : null;
};

/**
 * A bracket table from the environment: a JSON list of objects with
 * numeric "over", "base_tax" and "rate". Null when empty or when any
 * bracket is malformed (so a typo shows the "not set" warning instead of
 * deducting a wrong amount).
 */
$brackets = function (string $key): ?array {
    $raw = env($key);
    if (! is_string($raw) || trim($raw) === '') {
        return null;
    }
    $list = json_decode($raw, true);
    if (! is_array($list) || $list === [] || array_keys($list) !== range(0, count($list) - 1)) {
        return null;
    }
    foreach ($list as $bracket) {
        foreach (['over', 'base_tax', 'rate'] as $field) {
            if (! is_array($bracket) || ! isset($bracket[$field]) || ! is_numeric($bracket[$field])) {
                return null;
            }
        }
    }

    return array_map(fn (array $b) => [
        'over' => (float) $b['over'],
        'base_tax' => (float) $b['base_tax'],
        'rate' => (float) $b['rate'],
    ], $list);
};

/** A percent rule whose values all come from PAYROLL_{PREFIX}_*. */
$percent = fn (string $type, string $prefix) => [
    'type' => $type,
    'method' => 'percent',
    'base' => 'basic',
    'rate' => $number("PAYROLL_{$prefix}_RATE"),
    'employee_share' => $number("PAYROLL_{$prefix}_EMPLOYEE_SHARE"),
    'base_min' => $number("PAYROLL_{$prefix}_BASE_MIN"),
    'base_max' => $number("PAYROLL_{$prefix}_BASE_MAX"),
    'max' => null,
    'pre_tax' => true,
];

return [
    'deductions' => [
        $percent('GSIS', 'GSIS'),
        $percent('PhilHealth', 'PHILHEALTH'),
        $percent('Pag-IBIG', 'PAGIBIG'),
        [
            'type' => 'Withholding Tax',
            'method' => 'table',
            'base' => 'taxable',
            'brackets' => $brackets('PAYROLL_WITHHOLDING_TAX_BRACKETS'),
            'max' => null,
            'pre_tax' => false,
        ],
    ],
];
