<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LeaderStepRouter;
use Illuminate\Console\Command;

/**
 * One-off clean-up for existing data: items waiting for the Project Leader
 * step whose only possible reviewer is their own maker (see
 * LeaderStepRouter). Dry run by default; --apply moves them, recorded in
 * the audit trail as done by the given Program Leader.
 */
class RerouteLeaderSteps extends Command
{
    protected $signature = 'workflow:reroute-leader-steps
        {--apply : Move the items (without it, only count them)}
        {--as= : Email of the active Program Leader recorded as the actor (required with --apply)}';

    protected $description = 'Move items stuck waiting for "another Project Leader" to their reviewer (Finance/Admin or Program Leader).';

    public function handle(LeaderStepRouter $router): int
    {
        if (! $this->option('apply')) {
            $counts = $router->reroute(new User(), null, true);
            $this->info("Dry run. Expenses: {$counts['expense']}, purchase requests: {$counts['purchase_request']}. Payroll batches are checked only with --apply.");

            return self::SUCCESS;
        }

        $actor = User::query()
            ->where('email', strtolower((string) $this->option('as')))
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->first();
        if (! $actor) {
            $this->error('Pass --as=<email of an active Program Leader>.');

            return self::FAILURE;
        }

        $counts = $router->reroute($actor);
        $this->info("Rerouted. Expenses: {$counts['expense']}, purchase requests: {$counts['purchase_request']}, payroll batches: {$counts['payroll']}.");

        return self::SUCCESS;
    }
}
