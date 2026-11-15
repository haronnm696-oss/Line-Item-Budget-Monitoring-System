<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\ProjectTranche;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Budget requests from the Project Leader to the Program Leader: fund
 * release, realignment and liquidation. They show on the Program Leader
 * Approvals page as Pending; the Program Leader is notified on create and
 * the Project Leader on the decision.
 */
class BudgetRequestTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private ProjectTranche $tranche;
    private LibCategory $equipment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
        [$this->tranche, $this->equipment] = AuditLogger::muted(fn () => [
            ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 2, 'name' => 'Second tranche',
                'amount' => 250000, 'status' => 'due', 'planned_release_date' => '2026-12-01',
            ]),
            LibCategory::create([
                'project_id' => $this->project->id, 'category_type' => 'MOOE',
                'line_item_name' => 'Equipment', 'allocated_amount' => 50000,
            ]),
        ]);
    }

    public function test_a_fund_release_request_reaches_the_program_leader_as_pending(): void
    {
        $audit = AuditTrail::count();
        $id = $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'fund_release',
                'project_tranche_id' => $this->tranche->id,
                'justification' => 'First tranche is 92% liquidated; fieldwork starts in December.',
            ])
            ->assertCreated()
            ->assertJsonPath('budget_request.status', 'pending')
            ->assertJsonPath('budget_request.type', 'fund_release')
            ->assertJsonPath('budget_request.title', 'Fund release: Second tranche')
            ->assertJsonPath('budget_request.amount', 250000)
            ->json('budget_request.id');

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame(['requested', 'ApprovalRequest', $id, $this->project->id], [$entry->action, $entry->model_type, (int) $entry->model_id, (int) $entry->project_id]);

        // Program Leader: one notification and a Pending row on Approvals.
        $notes = $this->programLeader->notifications()->get();
        $this->assertCount(1, $notes);
        $this->assertSame('requested', $notes->first()->data['event']);
        $this->assertSame("/program-approvals?project={$this->project->id}", $notes->first()->data['link']);

        $rows = collect($this->actingAs($this->programLeader)->getJson('/api/program/approvals?status=pending')->assertOk()->json('approvals'));
        $row = $rows->firstWhere('id', $id);
        $this->assertNotNull($row, 'listed as pending on the Approvals page');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('First tranche is 92% liquidated; fieldwork starts in December.', $row['justification']);

        // A second request for the same tranche while one is pending.
        $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", ['type' => 'fund_release', 'project_tranche_id' => $this->tranche->id, 'justification' => 'Again.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.project_tranche_id.0', 'A fund release request for this tranche is already pending.');
    }

    public function test_realignment_is_limited_to_what_the_source_line_has_available(): void
    {
        // Supplies: 500,000 allocated, 100,000 verified spent, 50,000 pending.
        AuditLogger::muted(function () {
            $this->expenseIn('verified', null, true, ['amount' => 100000]);
            $this->expenseIn('submitted', null, true, ['amount' => 50000]);
        });
        $this->actingAs($this->projectLeader);
        $send = fn (float $amount) => $this->postJson("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'realignment',
            'from_lib_category_id' => $this->lineItem->id,
            'to_lib_category_id' => $this->equipment->id,
            'amount' => $amount,
            'justification' => 'A second centrifuge is needed for the assay.',
        ]);

        $send(350000.01)->assertStatus(422)->assertJsonPath('errors.amount.0', 'The source line item has only ₱350,000.00 available.');
        $send(120000)
            ->assertCreated()
            ->assertJsonPath('budget_request.title', 'Realignment: Supplies → Equipment')
            ->assertJsonPath('budget_request.payload.from_lib_category_id', $this->lineItem->id)
            ->assertJsonPath('budget_request.payload.to_lib_category_id', $this->equipment->id)
            ->assertJsonPath('budget_request.payload.amount', 120000);

        $this->postJson("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'realignment', 'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $this->lineItem->id,
            'amount' => 10, 'justification' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['to_lib_category_id']);
    }

    public function test_a_liquidation_needs_documents_and_stores_them(): void
    {
        $this->actingAs($this->projectLeader);
        $this->postJson("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'liquidation', 'amount' => 180000, 'justification' => 'Liquidation of the first tranche.',
        ])->assertStatus(422)->assertJsonPath('errors.files.0', 'Attach at least one supporting document.');

        $audit = AuditTrail::count();
        $id = $this->post("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'liquidation',
            'title' => 'Liquidation report, tranche 1',
            'amount' => 180000,
            'justification' => 'Liquidation of the first tranche.',
            'files' => [
                UploadedFile::fake()->create('liquidation.pdf', 200, 'application/pdf'),
                UploadedFile::fake()->create('ORs.jpg', 100, 'image/jpeg'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('budget_request.documents_count', 2)
            ->json('budget_request.id');

        // One "requested" entry plus one per uploaded document.
        $this->assertSame($audit + 3, AuditTrail::count());
        $this->assertSame(['requested', 'created', 'created'], AuditTrail::where('id', '>', 0)->orderBy('id')->get()->slice(-3)->pluck('action')->values()->all());

        $this->actingAs($this->programLeader)->getJson("/api/approvals/{$id}/documents")->assertOk()->assertJsonCount(2, 'documents');
        $this->actingAs($this->outsider)->getJson("/api/approvals/{$id}/documents")->assertForbidden();

        $this->actingAs($this->projectLeader)->post("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'liquidation', 'amount' => 1, 'justification' => 'x',
            'files' => [UploadedFile::fake()->create('notes.txt', 5, 'text/plain')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['files.0']);
    }

    public function test_only_the_project_leader_of_the_project_creates_budget_requests(): void
    {
        $payload = ['type' => 'fund_release', 'project_tranche_id' => $this->tranche->id, 'justification' => 'Needed.'];
        foreach ([$this->assistant, $this->finance, $this->researcher, $this->programLeader, $this->outsider] as $user) {
            $this->actingAs($user)->postJson("/api/projects/{$this->project->id}/budget-requests", $payload)->assertForbidden();
        }

        // A Project Leader of another project is not a Project Leader here.
        $otherLeader = AuditLogger::muted(function () {
            $user = $this->makePerson('project-leader');
            $this->assignTo($user, $this->otherProject, 'project-leader');

            return $user;
        });
        $this->actingAs($otherLeader)->postJson("/api/projects/{$this->project->id}/budget-requests", $payload)->assertForbidden();

        // Justification is required.
        $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", array_merge($payload, ['justification' => '   ']))
            ->assertStatus(422)->assertJsonPath('errors.justification.0', 'A justification is required.');

        $this->assertSame(0, ApprovalRequest::count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('notifications')->count());
    }

    public function test_the_project_leader_is_notified_of_the_decision(): void
    {
        $id = $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'fund_release', 'project_tranche_id' => $this->tranche->id, 'justification' => 'Needed for December.',
            ])->assertCreated()->json('budget_request.id');

        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$id}/decision", ['decision' => 'approved', 'remarks' => 'Release on Dec 1.'])
            ->assertOk();

        $notes = $this->projectLeader->notifications()->get();
        $this->assertCount(1, $notes);
        $this->assertSame('approved', $notes->first()->data['event']);
        $this->assertSame("/my-requests?project={$this->project->id}", $notes->first()->data['link']);
        $this->assertStringContainsString('Release on Dec 1.', $notes->first()->data['message']);
        $this->assertSame('released', $this->tranche->fresh()->status);

        // A reviewed request takes no more documents.
        $this->actingAs($this->projectLeader)->post("/api/approvals/{$id}/documents", [
            'file' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'), 'document_type' => 'other',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // The project's request list shows it.
        $this->getJson("/api/projects/{$this->project->id}/budget-requests")
            ->assertOk()->assertJsonPath('budget_requests.0.status', 'approved');
    }

    public function test_a_rejection_reaches_the_project_leader_with_the_remarks(): void
    {
        $id = $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'realignment', 'from_lib_category_id' => $this->lineItem->id,
                'to_lib_category_id' => $this->equipment->id, 'amount' => 1000, 'justification' => 'Move savings.',
            ])->assertCreated()->json('budget_request.id');

        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$id}/decision", ['decision' => 'rejected', 'remarks' => 'Not allowed by the funder.'])
            ->assertOk();

        $note = $this->projectLeader->notifications()->first();
        $this->assertSame('rejected', $note->data['event']);
        $this->assertSame('Not allowed by the funder.', $note->data['reason']);
        $this->assertSame(500000.0, (float) $this->lineItem->fresh()->allocated_amount);
    }
}
