<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Read-only data check for the Program Leader Overview's PS vs MOOE card.
 * Lists projects whose line items do not add up to the approved budget and
 * verified expenses that cannot be placed under PS or MOOE. Changes nothing.
 */
class AuditLineItems extends Command
{
    protected $signature = 'lib:audit-line-items';

    protected $description = 'List line-item/budget mismatches and uncategorized verified expenses (read-only)';

    public function handle(): int
    {
        $mismatches = self::budgetMismatches();
        $uncategorized = self::uncategorizedExpenses();

        $this->info('Projects whose line-item sum differs from total_approved_budget: ' . count($mismatches));
        if ($mismatches) {
            $this->table(['Code', 'Approved budget', 'Line items', 'Difference'], array_map(fn ($row) => [
                $row['code'],
                number_format($row['approved_budget'], 2),
                number_format($row['line_items_total'], 2),
                number_format($row['difference'], 2),
            ], $mismatches));
        }

        $this->info('Verified expenses without a PS/MOOE line item: ' . count($uncategorized));
        if ($uncategorized) {
            $this->table(['Expense ID', 'Project', 'Reference', 'Amount', 'Date'], array_map(fn ($row) => [
                $row['id'], $row['project'], $row['reference_no'] ?? '—', number_format($row['amount'], 2), $row['expense_date'],
            ], $uncategorized));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{code: string, approved_budget: float, line_items_total: float, difference: float}>
     */
    public static function budgetMismatches(): array
    {
        return Project::query()
            ->with('libCategories')
            ->orderBy('code')
            ->get()
            ->map(function (Project $project) {
                $approved = round((float) $project->total_approved_budget, 2);
                $lineItems = round((float) $project->libCategories->sum('allocated_amount'), 2);

                return [
                    'code' => (string) $project->code,
                    'approved_budget' => $approved,
                    'line_items_total' => $lineItems,
                    'difference' => round($lineItems - $approved, 2),
                ];
            })
            ->filter(fn (array $row) => abs($row['difference']) >= 0.005)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function uncategorizedExpenses(): array
    {
        return Expense::query()
            ->where('status', 'verified')
            ->with(['libCategory:id,category_type', 'project:id,code'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Expense $expense) => ! in_array($expense->libCategory?->category_type, ['PS', 'MOOE'], true))
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'project' => $expense->project?->code,
                'reference_no' => $expense->reference_no,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
