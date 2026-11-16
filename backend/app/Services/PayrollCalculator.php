<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Computes one person's monthly pay from the rules in config/payroll.php:
 * gross = monthly rate + allowance, then each government deduction in order.
 * A rule whose rate is not set deducts 0.00 and is reported in `unset`.
 */
class PayrollCalculator
{
    /** @param array<int, array<string, mixed>>|null $rules defaults to config('payroll.deductions') */
    public function __construct(private ?array $rules = null)
    {
    }

    /**
     * @return array{basic: float, allowance: float, gross: float, deductions: array<int, array{type: string, amount: float}>, total_deductions: float, net: float, unset: array<int, string>}
     */
    public function compute(float $basic, float $allowance = 0.0): array
    {
        $basic = round(max(0, $basic), 2);
        $allowance = round(max(0, $allowance), 2);
        $gross = round($basic + $allowance, 2);
        $preTax = 0.0;
        $deductions = [];

        foreach ($this->rules() as $rule) {
            $base = match ($rule['base'] ?? 'basic') {
                'basic' => $basic,
                'gross' => $gross,
                'taxable' => max(0, $gross - $preTax),
                default => throw new InvalidArgumentException("Unknown payroll base: {$rule['base']}"),
            };

            $amount = $this->ruleIsSet($rule) ? $this->amount($rule, $base) : 0.0;
            if (isset($rule['max']) && $rule['max'] !== null) {
                $amount = min($amount, (float) $rule['max']);
            }
            $amount = round(max(0, $amount), 2);

            if (! empty($rule['pre_tax'])) {
                $preTax += $amount;
            }
            $deductions[] = ['type' => (string) $rule['type'], 'amount' => $amount];
        }

        $total = round(array_sum(array_column($deductions, 'amount')), 2);

        return [
            'basic' => $basic,
            'allowance' => $allowance,
            'gross' => $gross,
            'deductions' => $deductions,
            'total_deductions' => $total,
            'net' => round($gross - $total, 2),
            'unset' => $this->unsetRules(),
        ];
    }

    /** Names of rules whose rate / amount / brackets are not set yet. */
    public function unsetRules(): array
    {
        return array_values(array_map(
            fn (array $rule) => (string) $rule['type'],
            array_filter($this->rules(), fn (array $rule) => ! $this->ruleIsSet($rule))
        ));
    }

    private function rules(): array
    {
        return $this->rules ?? (array) config('payroll.deductions', []);
    }

    private function ruleIsSet(array $rule): bool
    {
        return match ($rule['method'] ?? null) {
            // A percent needs both the rate and the employee's share; a
            // missing share is never taken as 100%.
            'percent' => isset($rule['rate'], $rule['employee_share'])
                && (float) $rule['rate'] >= 0
                && (float) $rule['employee_share'] >= 0 && (float) $rule['employee_share'] <= 1,
            'fixed' => isset($rule['amount']) && $rule['amount'] !== null,
            'table' => ! empty($rule['brackets']),
            default => throw new InvalidArgumentException('Unknown payroll method: ' . ($rule['method'] ?? 'none')),
        };
    }

    private function amount(array $rule, float $base): float
    {
        switch ($rule['method']) {
            case 'fixed':
                return (float) $rule['amount'];

            case 'percent':
                if (isset($rule['base_min']) && $rule['base_min'] !== null) {
                    $base = max($base, (float) $rule['base_min']);
                }
                if (isset($rule['base_max']) && $rule['base_max'] !== null) {
                    $base = min($base, (float) $rule['base_max']);
                }
                $share = array_key_exists('employee_share', $rule) && $rule['employee_share'] !== null ? (float) $rule['employee_share'] : 1.0;

                return $base * ((float) $rule['rate'] / 100) * $share;

            case 'table':
                $brackets = $rule['brackets'];
                usort($brackets, fn ($a, $b) => $a['over'] <=> $b['over']);
                $applied = null;
                foreach ($brackets as $bracket) {
                    if ($base > (float) $bracket['over']) {
                        $applied = $bracket;
                    }
                }
                if ($applied === null) {
                    return 0.0;
                }

                return (float) ($applied['base_tax'] ?? 0) + ($base - (float) $applied['over']) * ((float) ($applied['rate'] ?? 0) / 100);
        }

        return 0.0;
    }
}
