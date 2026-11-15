<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Purchase requests: draft -> submitted -> endorsed ("Approved by Project
 * Leader") or returned; a submit needs a quotation or invoice; no Finance
 * step. One audit entry per step, one notification per hand-off.
 */
class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
    }

    public function test_full_path_with_documents_audit_and_notifications(): void
    {
        $audit = AuditTrail::count();
        $id = $this->actingAs($this->assistant)
            ->postJson("/api/projects/{$this->project->id}/purchase-requests", $this->payload())
            ->assertCreated()
            ->assertJsonPath('purchase_request.workflow_status', 'draft')
            ->assertJsonPath('purchase_request.estimated_amount', 4500)
            ->json('purchase_request.id');
        $this->assertStep($audit, 'created', $id);

        // A submit needs a quotation or invoice.
        $this->postJson("/api/purchase-requests/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('errors.documents.0', 'Attach a quotation or invoice before submitting.');
        $this->upload($id, 'other')->assertCreated();
        $this->postJson("/api/purchase-requests/{$id}/submit")->assertStatus(422);

        $audit = AuditTrail::count();
        $this->upload($id, 'quotation')->assertCreated();
        $this->assertSame($audit + 1, AuditTrail::count()); // the upload

        $audit = AuditTrail::count();
        $this->postJson("/api/purchase-requests/{$id}/submit")->assertOk()->assertJsonPath('purchase_request.workflow_status', 'submitted');
        $this->assertStep($audit, 'submitted', $id);
        $this->assertNotified($this->projectLeader, 'submitted', $id);

        $audit = AuditTrail::count();
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$id}/endorse")
            ->assertOk()
            ->assertJsonPath('purchase_request.workflow_status', 'endorsed')
            ->assertJsonPath('purchase_request.workflow_label', 'Approved by Project Leader');
        $this->assertStep($audit, 'endorsed', $id);
        $this->assertNotified($this->assistant, 'endorsed', $id);
        $this->assertSame(2, DB::table('notifications')->count());

        // No Finance step and no edits after approval.
        $this->actingAs($this->finance)->postJson("/api/purchase-requests/{$id}/verify")->assertStatus(404);
        $this->actingAs($this->assistant)->patchJson("/api/purchase-requests/{$id}", ['quantity' => 9])->assertStatus(422);
        $this->upload($id, 'invoice')->assertStatus(422);
    }

    public function test_return_needs_a_reason_and_the_request_can_be_fixed_and_resubmitted(): void
    {
        $request = $this->submitted();

        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$request->id}/return", ['reason' => ''])->assertStatus(422);
        $audit = AuditTrail::count();
        $this->postJson("/api/purchase-requests/{$request->id}/return", ['reason' => 'Get a second quotation.'])
            ->assertOk()->assertJsonPath('purchase_request.workflow_status', 'returned');
        $this->assertStep($audit, 'returned', $request->id);
        $this->assertSame('Get a second quotation.', AuditTrail::latest('id')->value('reason'));
        $this->assertNotified($this->assistant, 'returned', $request->id);

        $this->actingAs($this->assistant)->patchJson("/api/purchase-requests/{$request->id}", ['estimated_amount' => 4000])
            ->assertOk()->assertJsonPath('purchase_request.estimated_amount', 4000);
        $this->postJson("/api/purchase-requests/{$request->id}/submit")
            ->assertOk()->assertJsonPath('purchase_request.return_reason', null);
    }

    public function test_roles_maker_checker_and_scoping(): void
    {
        foreach ([$this->projectLeader, $this->researcher, $this->programLeader, $this->outsider] as $user) {
            $this->actingAs($user)
                ->postJson("/api/projects/{$this->project->id}/purchase-requests", $this->payload())
                ->assertForbidden();
        }
        $this->actingAs($this->finance)->postJson("/api/projects/{$this->project->id}/purchase-requests", $this->payload())->assertCreated();

        $request = $this->submitted();
        foreach ([$this->assistant, $this->finance, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->postJson("/api/purchase-requests/{$request->id}/endorse")->assertForbidden();
            $this->actingAs($user)->postJson("/api/purchase-requests/{$request->id}/return", ['reason' => 'x'])->assertForbidden();
        }
        $this->actingAs($this->outsider)->getJson("/api/projects/{$this->project->id}/purchase-requests")->assertForbidden();
        $this->actingAs($this->outsider)->postJson("/api/purchase-requests/{$request->id}/endorse")->assertForbidden();

        // A Project Leader cannot approve a request they made (e.g. one recorded under their name).
        $own = $this->submitted($this->projectLeader);
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$own->id}/endorse")
            ->assertForbidden()->assertJsonPath('message', 'You cannot approve or return a purchase request you made.');

        // Invalid transition.
        $draft = $this->draft();
        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$draft->id}/endorse")
            ->assertStatus(422)
            ->assertJsonPath('errors.workflow_status.0', 'This purchase request cannot be approved while it is draft.');
    }

    public function test_only_the_requester_deletes_and_only_a_draft(): void
    {
        $mine = $this->draft();
        $theirs = $this->draft($this->finance);
        $submitted = $this->submitted();

        $this->actingAs($this->assistant)->deleteJson("/api/purchase-requests/{$theirs->id}")->assertForbidden();
        $this->deleteJson("/api/purchase-requests/{$submitted->id}")->assertStatus(422);

        $audit = AuditTrail::count();
        $this->deleteJson("/api/purchase-requests/{$mine->id}")->assertOk();
        $this->assertStep($audit, 'deleted', $mine->id);
        $this->assertSoftDeleted('purchase_requests', ['id' => $mine->id]);

        $ids = array_column($this->getJson("/api/projects/{$this->project->id}/purchase-requests")->json('purchase_requests'), 'id');
        $this->assertEqualsCanonicalizing([$theirs->id, $submitted->id], $ids);
        $filtered = $this->getJson("/api/projects/{$this->project->id}/purchase-requests?workflow_status=submitted")->json('purchase_requests');
        $this->assertSame([$submitted->id], array_column($filtered, 'id'));
    }

    public function test_validation(): void
    {
        $foreign = AuditLogger::muted(fn () => \App\Models\LibCategory::create([
            'project_id' => $this->otherProject->id, 'category_type' => 'MOOE', 'line_item_name' => 'X', 'allocated_amount' => 1,
        ]));
        $this->actingAs($this->assistant);
        $this->postJson("/api/projects/{$this->project->id}/purchase-requests", $this->payload(['lib_category_id' => $foreign->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['lib_category_id']);
        $this->postJson("/api/projects/{$this->project->id}/purchase-requests", $this->payload(['quantity' => 0, 'estimated_amount' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['quantity', 'estimated_amount']);
    }

    // ------------------------------------------------------------ helpers

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'lib_category_id' => $this->lineItem->id,
            'description' => 'Laboratory glassware set',
            'quantity' => 3,
            'estimated_amount' => 4500,
        ], $overrides);
    }

    private function draft(?User $by = null): PurchaseRequest
    {
        return AuditLogger::muted(fn () => PurchaseRequest::create($this->payload([
            'project_id' => $this->project->id,
            'requested_by' => ($by ?? $this->assistant)->id,
            'workflow_status' => 'draft',
        ])));
    }

    private function submitted(?User $by = null): PurchaseRequest
    {
        $request = $this->draft($by);
        AuditLogger::muted(function () use ($request, $by) {
            $request->documents()->create([
                'uploaded_by' => ($by ?? $this->assistant)->id, 'document_type' => 'quotation', 'file_name' => 'q.pdf',
                'file_path' => 'documents/purchase-requests/q.pdf', 'disk' => 'local', 'uploaded_at' => now(),
            ]);
            $request->update(['workflow_status' => 'submitted', 'submitted_at' => now()]);
        });

        return $request->fresh();
    }

    private function upload(int $id, string $type)
    {
        return $this->actingAs($this->assistant)->post("/api/purchase-requests/{$id}/documents", [
            'file' => UploadedFile::fake()->create('quote.pdf', 30, 'application/pdf'),
            'document_type' => $type,
        ], ['Accept' => 'application/json']);
    }

    private function assertStep(int $before, string $action, int $id): void
    {
        $this->assertSame($before + 1, AuditTrail::count(), "one audit entry for {$action}");
        $entry = AuditTrail::latest('id')->firstOrFail();
        $this->assertSame([$action, 'PurchaseRequest', $id, $this->project->id], [$entry->action, $entry->model_type, (int) $entry->model_id, (int) $entry->project_id]);
    }

    private function assertNotified(User $user, string $event, int $id): void
    {
        $rows = $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'purchase_request' && ($n->data['event'] ?? null) === $event);
        $this->assertCount(1, $rows, "{$user->name}: one purchase request {$event} notification");
        $this->assertSame("/purchase-requests?project={$this->project->id}&request={$id}", $rows->first()->data['link']);
    }
}
