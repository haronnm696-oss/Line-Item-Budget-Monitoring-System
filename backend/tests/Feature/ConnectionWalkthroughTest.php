<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * End to end, one project, one user per role. The Program Leader creates the
 * project (with its Project Leader), its Line-Item Budget and tranche through
 * the set-up endpoints and assigns everyone else through the assignment
 * endpoints. Walks the whole chain
 * (expense, rejection, payroll with and without the Project Leader's pay,
 * fund release, Researcher read-only, unassignment) and checks at each
 * hand-off: exactly one notification to the right person and one audit
 * entry. The browser version is docs/manual-tests/2026-11-17-connection-walkthrough.md.
 */
class ConnectionWalkthroughTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private User $prg;
    private User $pl;
    private User $ra;
    private User $fin;
    private User $res;
    private LibCategory $supplies;
    private LibCategory $salaries;
    private ProjectTranche $tranche;
    /** @var array<int, string> notification ids before the current step */
    private array $seenNotifications = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local', 'payroll.deductions' => []]);
        Storage::fake('local');

        AuditLogger::muted(function () {
            // Accounts and a funder only. The project, its Line-Item Budget,
            // tranches and every assignment are made through the endpoints.
            \App\Models\FundingAgency::create(['name' => 'Department of Science and Technology - PCAARRD', 'acronym' => 'DOST-PCAARRD']);
            $this->prg = $this->makePerson('program-leader');
            $this->pl = $this->makePerson('project-leader');
            $this->ra = $this->makePerson('research-assistant');
            $this->fin = $this->makePerson('finance-admin');
            $this->res = $this->makePerson('researcher');
        });
    }

    public function test_the_whole_chain_on_one_project(): void
    {
        // ------------------------------------------------ 0a. Project set-up by the Program Leader
        $this->actingAs($this->pl)->getJson('/api/projects')->assertOk()->assertJsonCount(0);
        $this->actingAs($this->prg);
        $options = $this->getJson('/api/program/project-options')->assertOk();
        $audit = AuditTrail::count();
        $p = $this->postJson('/api/program/projects', [
            'title' => 'Halal Goat Value Chain Development', 'code' => 'RP-2026-009',
            'funding_agency_id' => $options->json('funders.0.id'), 'funder_reference' => 'PCAARRD-GIA-2026-0457',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_approved_budget' => 1000000,
            'project_leader_id' => $this->pl->id,
        ])->assertCreated()->json('project.id');
        $this->assertAudit($audit, 'created');
        $this->project = Project::findOrFail($p);

        // Before any line item, nothing can be recorded against the project.
        $this->getJson("/api/program/projects/{$p}")->assertOk()->assertJsonPath('line_items.groups', []);
        $lineItem = function (string $type, string $name, float $amount) use ($p) {
            $audit = AuditTrail::count();
            $id = $this->postJson("/api/program/projects/{$p}/line-items", ['category_type' => $type, 'line_item_name' => $name, 'allocated_amount' => $amount])
                ->assertCreated()->json('line_item.id');
            $this->assertAudit($audit, 'created');

            return LibCategory::findOrFail($id);
        };
        $this->supplies = $lineItem('MOOE', 'Supplies', 500000);
        $this->salaries = $lineItem('PS', 'Salaries', 400000);
        $this->lineItem = $this->supplies;
        $this->postJson("/api/program/projects/{$p}/line-items", ['category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => 100000.01])
            ->assertStatus(422)->assertJsonValidationErrors(['allocated_amount']); // over the 1,000,000 total

        $audit = AuditTrail::count();
        $this->tranche = ProjectTranche::findOrFail($this->postJson("/api/program/projects/{$p}/tranches", ['amount' => 250000, 'planned_release_date' => '2026-12-01'])
            ->assertCreated()->assertJsonPath('tranche.name', 'Release 1')->json('tranche.id'));
        $this->assertAudit($audit, 'created');

        // The leader set at creation has the project at once.
        $this->assertSame([$p], $this->pl->fresh()->projectIdsAs('project-leader'));

        // ------------------------------------------------ 0b. Team assignments by the Program Leader
        foreach ([[$this->ra, 'research-assistant'], [$this->fin, 'finance-admin'], [$this->res, 'researcher']] as [$user, $role]) {
            $audit = AuditTrail::count();
            $this->actingAs($this->prg)->postJson('/api/program/assignments', ['user_id' => $user->id, 'project_id' => $p, 'role' => $role])->assertCreated();
            $this->assertAudit($audit, 'assignment_created');

            // Takes effect at once, with the same answer on every resolver.
            $row = collect($this->actingAs($user)->getJson('/api/projects')->assertOk()->json())->firstWhere('id', $p);
            $this->assertSame([$role], $row['my_roles']);
            $this->assertSame($role, $row['my_role']);
            $this->assertSame([$p], $user->fresh()->projectIdsAs($role));
        }
        // The Program Leader's Projects page names the leader that pages use.
        $this->actingAs($this->prg)->getJson("/api/program/projects/{$p}")->assertOk()->assertJsonPath('project.project_leader', $this->pl->name);
        // Every Project Leader page answers for this project (the reported bug).
        $this->actingAs($this->pl);
        foreach (['leader-overview', 'leader-badges', 'review-queue', 'budget-requests', 'transactions', 'budget-summary', 'reports/form-b'] as $page) {
            $this->getJson("/api/projects/{$p}/{$page}")->assertOk();
        }

        // ------------------------------------------------ 1. Expense: submit, return, resubmit, approve, verify
        $expense = $this->recordAndSubmit(2500.50);
        $this->assertHandOff($this->pl, 'expense', 'submitted');
        $this->actingAs($this->pl);
        $this->assertContains($expense, collect($this->getJson("/api/projects/{$p}/review-queue?tab=waiting")->json('items'))->pluck('id')->all());
        $this->getJson("/api/projects/{$p}/leader-badges")->assertJsonPath('submissions', 1);
        $this->getJson('/api/notifications/unread-count')->assertOk();

        [$audit, $notes] = $this->mark();
        $this->postJson("/api/expenses/{$expense}/return", ['reason' => 'The receipt is unreadable.'])->assertOk();
        $this->assertStep($audit, $notes, 'returned', $this->ra, 'expense');
        $this->actingAs($this->ra)->getJson("/api/projects/{$p}/my-submissions/expense/{$expense}")->assertOk()
            ->assertJsonPath('item.workflow_status', 'returned')
            ->assertJsonPath('item.return_reason', 'The receipt is unreadable.')
            ->assertJsonPath('item.returned_by.id', $this->pl->id);
        $this->getJson("/api/projects/{$p}/assistant-badges")->assertJsonPath('my_submissions', 1);

        [$audit, $notes] = $this->mark();
        $this->postJson("/api/expenses/{$expense}/submit")->assertOk();
        $this->assertStep($audit, $notes, 'submitted', $this->pl, 'expense');

        [$audit, $notes] = $this->mark();
        $this->actingAs($this->pl)->postJson("/api/expenses/{$expense}/endorse")->assertOk();
        $this->assertStep($audit, $notes, 'endorsed', $this->fin, 'expense');
        $this->actingAs($this->fin);
        $this->assertContains($expense, collect($this->getJson("/api/projects/{$p}/verification-queue")->json('items'))->pluck('id')->all());
        $this->getJson("/api/projects/{$p}/finance-badges")->assertJsonPath('verification', 1);

        $this->assertEquals([0, 0, 0, 0, 0], $this->spentEverywhere());
        [$audit, $notes] = $this->mark();
        $this->actingAs($this->fin)->postJson("/api/expenses/{$expense}/verify")->assertOk();
        $this->assertStep($audit, $notes, 'verified', $this->ra, 'expense');
        $this->assertEquals(array_fill(0, 5, 2500.5), $this->spentEverywhere());

        // ------------------------------------------------ 2. Finance rejects a second expense
        $second = $this->recordAndSubmit(999);
        $this->actingAs($this->pl)->postJson("/api/expenses/{$second}/endorse")->assertOk();
        [$audit, $notes] = $this->mark();
        $this->actingAs($this->fin)->postJson("/api/expenses/{$second}/reject", ['reason' => 'Amount does not match the OR.'])->assertOk();
        $this->assertStep($audit, $notes, 'rejected', $this->ra, 'expense');
        $this->actingAs($this->ra)->getJson("/api/projects/{$p}/my-submissions/expense/{$second}")->assertOk()
            ->assertJsonPath('item.return_reason', 'Amount does not match the OR.')
            ->assertJsonPath('item.returned_by.id', $this->fin->id);
        $this->assertEquals(array_fill(0, 5, 2500.5), $this->spentEverywhere());

        // ------------------------------------------------ 3. Payroll: RA prepares, PL approves, Finance verifies
        $this->actingAs($this->fin);
        $this->patchJson("/api/projects/{$p}/pay-rates/{$this->ra->id}", ['monthly_rate' => 20000])->assertOk();
        $this->patchJson("/api/projects/{$p}/pay-rates/{$this->res->id}", ['monthly_rate' => 30000])->assertOk();
        $batch = $this->prepareBatch('2026-11');

        [$audit, $notes] = $this->mark();
        $this->actingAs($this->ra)->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();
        $this->assertStep($audit, $notes, 'submitted', $this->pl, 'payroll');
        [$audit, $notes] = $this->mark();
        $this->actingAs($this->pl)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();
        $this->assertStep($audit, $notes, 'endorsed', $this->fin, 'payroll');
        [$audit, $notes] = $this->mark();
        $this->actingAs($this->fin)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
        $this->assertStep($audit, $notes, 'verified', $this->ra, 'payroll');
        $this->assertEquals(array_fill(0, 5, 2500.5), $this->spentEverywhere()); // payroll is not LIB spent

        // A batch that pays the Project Leader goes to the Program Leader instead.
        $this->actingAs($this->fin)->patchJson("/api/projects/{$p}/pay-rates/{$this->pl->id}", ['monthly_rate' => 45000])->assertOk();
        $escalated = $this->prepareBatch('2026-12');
        [$audit, $notes] = $this->mark();
        $this->actingAs($this->ra)->postJson("/api/payroll-batches/{$escalated->id}/submit")->assertOk()
            ->assertJsonPath('batch.escalated_step', 'approve');
        $this->assertSame($audit + 1, AuditTrail::count());
        // The Program Leader decides (one); the paid Project Leader is told why (one).
        $this->assertSame($notes + 2, DB::table('notifications')->count());
        $this->assertSame(1, $this->countNotes($this->prg, 'payroll', 'submitted'));
        $this->assertSame(1, $this->countNotes($this->pl, 'payroll', 'escalated'));
        $this->actingAs($this->pl)->postJson("/api/payroll-batches/{$escalated->id}/endorse")->assertForbidden();

        $approval = ApprovalRequest::findOrFail($escalated->fresh()->approval_request_id);
        $this->assertContains($approval->id, collect($this->actingAs($this->prg)->getJson('/api/program/approvals?status=pending')->json('approvals'))->pluck('id')->all());
        [$audit, $notes] = $this->mark();
        $this->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('approved', AuditTrail::latest('id')->value('action'));
        $this->assertSame($notes + 1, DB::table('notifications')->count());
        $this->assertSame(2, $this->countNotes($this->fin, 'payroll', 'endorsed')); // one per batch
        $this->actingAs($this->fin)->postJson("/api/payroll-batches/{$escalated->id}/verify")->assertOk()->assertJsonPath('batch.workflow_status', 'verified');

        // ------------------------------------------------ 4. Fund release: PL requests, Program Leader approves
        $released = fn () => $this->actingAs($this->pl)->getJson("/api/projects/{$p}/tranches")->assertOk()->json('summary.released_amount');
        $this->assertEquals(0, $released());
        [$audit, $notes] = $this->mark();
        $request = $this->actingAs($this->pl)->postJson("/api/projects/{$p}/budget-requests", [
            'type' => 'fund_release', 'project_tranche_id' => $this->tranche->id, 'justification' => 'Fieldwork starts in December.',
        ])->assertCreated()->json('budget_request.id');
        $this->assertStep($audit, $notes, 'requested', $this->prg, 'budget_request');
        $this->assertContains($request, collect($this->actingAs($this->prg)->getJson('/api/program/approvals?status=pending')->json('approvals'))->pluck('id')->all());

        [$audit, $notes] = $this->mark();
        $this->postJson("/api/program/approvals/{$request}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame($notes + 1, DB::table('notifications')->count());
        $this->assertSame(1, $this->countNotes($this->pl, 'budget_request', 'approved'));
        $this->assertSame($audit + 1, AuditTrail::count());
        $mine = collect($this->actingAs($this->pl)->getJson("/api/projects/{$p}/budget-requests")->assertOk()->json('budget_requests'))->firstWhere('id', $request);
        $this->assertSame('approved', $mine['status']);
        $this->assertEquals(250000, $released());
        // Released: the Program Leader can no longer change or remove that tranche.
        $this->actingAs($this->prg)->patchJson("/api/program/tranches/{$this->tranche->id}", ['amount' => 1])->assertStatus(422);

        // ------------------------------------------------ 5. Researcher: reads verified data, every write is 403
        $this->actingAs($this->res);
        $this->getJson("/api/projects/{$p}/budget-summary")->assertOk()->assertJsonPath('kpis.total_spent', 2500.5);
        $this->assertSame([$expense], collect($this->getJson("/api/projects/{$p}/transactions")->assertOk()->json('transactions'))->pluck('id')->all());
        $writes = [
            ['POST', "/api/projects/{$p}/expenses", $this->expensePayload()],
            ['PATCH', "/api/expenses/{$second}", ['description' => 'x']],
            ['POST', "/api/expenses/{$second}/submit", []],
            ['POST', "/api/expenses/{$second}/endorse", []],
            ['POST', "/api/expenses/{$second}/return", ['reason' => 'x']],
            ['POST', "/api/expenses/{$second}/verify", []],
            ['POST', "/api/expenses/{$second}/reject", ['reason' => 'x']],
            ['POST', "/api/expenses/{$expense}/documents", ['document_type' => 'receipt', 'file' => $this->uniqueUpload('r.pdf')]],
            ['POST', "/api/projects/{$p}/payroll", ['month' => '2027-01', 'lib_category_id' => $this->salaries->id]],
            ['POST', "/api/projects/{$p}/payroll/compute", ['month' => '2026-12']],
            ['POST', "/api/payroll-batches/{$batch->id}/endorse", []],
            ['POST', "/api/payroll-batches/{$batch->id}/verify", []],
            ['PATCH', "/api/projects/{$p}/pay-rates/{$this->ra->id}", ['monthly_rate' => 1]],
            ['POST', "/api/projects/{$p}/purchase-requests", ['lib_category_id' => $this->supplies->id, 'description' => 'x', 'quantity' => 1, 'estimated_amount' => 1]],
            ['POST', "/api/projects/{$p}/budget-requests", ['type' => 'liquidation', 'title' => 'x', 'amount' => 1, 'justification' => 'x']],
            ['POST', '/api/program/assignments', ['user_id' => $this->res->id, 'project_id' => $p, 'role' => 'project-leader']],
        ];
        $failures = [];
        foreach ($writes as [$method, $uri, $body]) {
            $status = $this->json($method, $uri, $body)->getStatusCode();
            if ($status !== 403) {
                $failures[] = "{$method} {$uri} -> {$status}";
            }
        }
        $this->assertSame([], $failures);
        $this->getJson("/api/projects/{$p}/pay-rates")->assertForbidden();

        // ------------------------------------------------ 6. Unassigning removes access at once
        $audit = AuditTrail::count();
        $this->actingAs($this->prg)->deleteJson("/api/program/assignments/{$this->ra->id}/{$p}", ['reason' => 'Moved to another project.'])->assertOk();
        $this->assertAudit($audit, 'assignment_removed');
        $this->actingAs($this->ra);
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(0);
        $this->assertSame([], $this->ra->fresh()->projectIdsAs('research-assistant'));
        $this->getJson("/api/projects/{$p}/assistant-overview")->assertForbidden();
        $this->getJson("/api/projects/{$p}/expenses")->assertForbidden();
        $this->postJson("/api/projects/{$p}/expenses", $this->expensePayload())->assertForbidden();
        $this->postJson("/api/expenses/{$second}/submit")->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    /** RA records an expense, attaches the receipt and submits it. Returns the id. */
    private function recordAndSubmit(float $amount): int
    {
        $this->actingAs($this->ra);
        $id = $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['amount' => $amount]))->assertCreated()->json('expense.id');
        $this->post("/api/expenses/{$id}/documents", ['document_type' => 'receipt', 'file' => $this->uniqueUpload("receipt-{$id}.pdf")], ['Accept' => 'application/json'])->assertCreated();
        [$audit, $notes] = $this->mark();
        $this->postJson("/api/expenses/{$id}/submit")->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('submitted', AuditTrail::latest('id')->value('action'));
        $this->assertSame($notes + 1, DB::table('notifications')->count());

        return $id;
    }

    /** RA prepares the month and attaches the payroll file. */
    private function prepareBatch(string $month): PayrollBatch
    {
        $this->actingAs($this->ra)->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $month, 'lib_category_id' => $this->salaries->id])->assertCreated();
        [$year, $m] = array_map('intval', explode('-', $month));
        $batch = PayrollBatch::where('project_id', $this->project->id)->where('pay_year', $year)->where('pay_month', $m)->firstOrFail();
        $this->post("/api/payroll-batches/{$batch->id}/documents", ['document_type' => 'payroll_file', 'file' => $this->uniqueUpload("payroll-{$month}.pdf")], ['Accept' => 'application/json'])->assertCreated();

        return $batch;
    }

    /** Spent as shown to the Project Leader, Research Assistant, Finance, Researcher and Program Leader. */
    private function spentEverywhere(): array
    {
        $p = $this->project->id;

        return [
            $this->actingAs($this->pl)->getJson("/api/projects/{$p}/leader-overview")->assertOk()->json('kpis.total_spent'),
            $this->actingAs($this->ra)->getJson("/api/projects/{$p}/balancing")->assertOk()->json('totals.spent'),
            $this->actingAs($this->fin)->getJson("/api/projects/{$p}/finance-overview")->assertOk()->json('kpis.total_spent'),
            $this->actingAs($this->res)->getJson("/api/projects/{$p}/budget-summary")->assertOk()->json('kpis.total_spent'),
            $this->actingAs($this->prg)->getJson("/api/program/projects/{$p}")->assertOk()->json('project.spent'),
        ];
    }

    /** @return array{0: int, 1: int} audit and notification counts before a step */
    private function mark(): array
    {
        $this->seenNotifications = DB::table('notifications')->pluck('id')->all();

        return [AuditTrail::count(), DB::table('notifications')->count()];
    }

    /** One audit entry with this action, and exactly one new notification, to $recipient. */
    private function assertStep(int $audit, int $notes, string $action, User $recipient, string $kind): void
    {
        $this->assertAudit($audit, $action);
        $this->assertSame($notes + 1, DB::table('notifications')->count(), "{$action}: exactly one notification");
        // Notification ids are UUIDs: the new one is the row added after the first $notes.
        $latest = DB::table('notifications')->orderBy('created_at')->get()
            ->first(fn ($row) => ! in_array($row->id, $this->seenNotifications, true));
        $this->seenNotifications = DB::table('notifications')->pluck('id')->all();
        $this->assertNotNull($latest);
        $this->assertSame($recipient->id, (int) $latest->notifiable_id, "{$action}: notification goes to {$recipient->name}");
        $data = json_decode($latest->data, true);
        $this->assertSame([$kind, $action], [$data['kind'] ?? null, $data['event'] ?? null]);
    }

    private function assertAudit(int $before, string $action): void
    {
        $this->assertSame($before + 1, AuditTrail::count(), "one audit entry for {$action}");
        $this->assertSame($action, AuditTrail::latest('id')->value('action'));
    }

    /** The recipient already holds the notification of a hand-off checked by recordAndSubmit(). */
    private function assertHandOff(User $user, string $kind, string $event): void
    {
        $this->assertSame(1, $this->countNotes($user, $kind, $event));
    }

    private function countNotes(User $user, string $kind, string $event): int
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind && ($n->data['event'] ?? null) === $event)->count();
    }
}
