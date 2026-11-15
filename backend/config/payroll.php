<?php

/*
|--------------------------------------------------------------------------
| Payroll deduction rules
|--------------------------------------------------------------------------
|
| Government deductions applied by "Compute payroll", in this order. EVERY
| RATE IS UNSET (null) ON PURPOSE: fill in the official rates that apply to
| your project staff before relying on the numbers. An unset rule deducts
| 0.00, and the payroll API lists it under `unset_rules` so the Payroll page
| shows a warning. Remove a rule that does not apply (for example GSIS for
| contract-of-service staff), or add one. Changes need a config cache clear
| (`php artisan config:clear`; on Render, a redeploy).
|
| Gross pay = monthly rate + allowance.
|
| Each rule:
|   type            Name shown on the payroll and stored per deduction row.
|   method          'percent' | 'fixed' | 'table'
|   base            What a percent / table applies to:
|                     'basic'   = monthly rate
|                     'gross'   = monthly rate + allowance
|                     'taxable' = gross minus the rules above marked pre_tax
|   rate            percent: e.g. 5 for 5% (null = not set)
|   employee_share  percent: share paid by the employee, 0..1 (e.g. 0.5 when
|                   the contribution is split with the employer). Default 1.
|   base_min        percent: the base is raised to at least this (salary floor)
|   base_max        percent: the base is capped at this (salary ceiling)
|   max             any method: cap on the deduction amount
|   amount          fixed: the monthly amount (null = not set)
|   brackets        table: list of ['over' => X, 'base_tax' => Y, 'rate' => Z]
|                   meaning: base_tax + Z% of the part of the base above X,
|                   using the highest bracket whose 'over' the base exceeds
|                   (null = not set)
|   pre_tax         true when the deduction is subtracted before tax
|
*/

return [
    'deductions' => [
        [
            'type' => 'GSIS',
            'method' => 'percent',
            'base' => 'basic',
            'rate' => null,
            'employee_share' => 1,
            'base_min' => null,
            'base_max' => null,
            'max' => null,
            'pre_tax' => true,
        ],
        [
            'type' => 'PhilHealth',
            'method' => 'percent',
            'base' => 'basic',
            'rate' => null,
            'employee_share' => 1,
            'base_min' => null,
            'base_max' => null,
            'max' => null,
            'pre_tax' => true,
        ],
        [
            'type' => 'Pag-IBIG',
            'method' => 'percent',
            'base' => 'basic',
            'rate' => null,
            'employee_share' => 1,
            'base_min' => null,
            'base_max' => null,
            'max' => null,
            'pre_tax' => true,
        ],
        [
            'type' => 'Withholding Tax',
            'method' => 'table',
            'base' => 'taxable',
            'brackets' => null,
            'max' => null,
            'pre_tax' => false,
        ],
    ],
];
