<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\ProjectPersonnel;
use App\Models\ProjectUser;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * The Personnel section: the project team appointed by Special Order, paid
 * PS honoraria through payroll. Placeholder names and fake account numbers.
 */
class PersonnelTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    /** Fake account number used only in tests. */
    private const ACCOUNT = '0000-1234-9170';

    private LibCategory $ps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures(); // project period 2026-01-01 .. 2026-12-31
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->ps = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS', 'expense_group' => 'Honoraria',
            'line_item_name' => 'Project Staff Level 3', 'allocated_amount' => 90000, 'funder_amount' => 90000,
        ]));
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->assistant->id,
            'position' => 'Project Staff Level 3',
            'lib_category_id' => $this->ps->id,
            'special_order_no' => 'SO No. 1111-OP s. 2025',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'monthly_honorarium' => '7500.00',
            'bank_account' => self::ACCOUNT,
        ], $overrides);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/personnel{$suffix}";
    }

    private function add(array $overrides = []): int
    {
        return $this->actingAs($this->programLeader)
            ->postJson("/api/program/projects/{$this->project->id}/personnel", $this->body($overrides))
            ->assertCreated()->json('personnel.id');
    }

    // ---------------------------------------------------------------- who

    public function test_only_the_program_leader_edits(): void
    {
        $id = $this->add();
        foreach ([$this->projectLeader, $this->finance, $this->assistant, $this->researcher, $this->outsider] as $user) {
            $this->actingAs($user)->postJson("/api/program/projects/{$this->project->id}/personnel", $this->body(['user_id' => $this->finance->id]))->assertForbidden();
            $this->actingAs($user)->patchJson("/api/program/personnel/{$id}", $this->body())->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/program/personnel/{$id}")->assertForbidden();
        }
        // The old pay-rate endpoint is gone.
        $this->actingAs($this->programLeader)->patchJson("/api/projects/{$this->project->id}/pay-rates/{$this->assistant->id}", ['monthly_rate' => 1])->assertNotFound();
    }

    public function test_who_sees_what(): void
    {
        $this->add();

        $full = $this->actingAs($this->programLeader)->getJson($this->url())->assertOk();
        $this->assertSame('edit', $full->json('access'));
        $this->assertSame(['start' => '2026-01-01', 'end' => '2026-12-31'], $full->json('period'));
        $this->assertNotEmpty($full->json('members'));
        $this->assertSame([['id' => $this->ps->id, 'name' => 'Project Staff Level 3', 'code' => null]], $full->json('ps_line_items'));
        $this->assertSame([
            'user_id' => $this->assistant->id, 'name' => $this->assistant->name, 'position' => 'Project Staff Level 3',
            'lib_category_id' => $this->ps->id, 'line_item' => 'Project Staff Level 3', 'special_order_no' => 'SO No. 1111-OP s. 2025',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'monthly_honorarium' => 7500,
            'bank_account_masked' => '****9170', 'special_order_document' => null,
        ], collect($full->json('personnel.0'))->except('id')->all());

        foreach ([$this->projectLeader, $this->finance] as $viewer) {
            $view = $this->actingAs($viewer)->getJson($this->url())->assertOk();
            $this->assertSame('view', $view->json('access'));
            $this->assertSame('****9170', $view->json('personnel.0.bank_account_masked'));
            $this->assertSame(7500, $view->json('personnel.0.monthly_honorarium'));
            $this->assertNull($view->json('members'));
        }

        // Research Assistant: name, position and period only.
        $limited = $this->actingAs($this->assistant)->getJson($this->url())->assertOk();
        $this->assertSame('limited', $limited->json('access'));
        $this->assertSame(['id', 'user_id', 'name', 'position', 'effective_from', 'effective_to'], array_keys($limited->json('personnel.0')));

        // Researchers and outsiders: 403.
        $this->actingAs($this->researcher)->getJson($this->url())->assertForbidden();
        $this->actingAs($this->outsider)->getJson($this->url())->assertForbidden();
    }

    // ---------------------------------------------------------------- masking

    public function test_the_bank_account_is_encrypted_and_never_in_full_in_the_api_or_audit(): void
    {
        $id = $this->add();

        $stored = DB::table('project_personnel')->where('id', $id)->value('bank_account');
        $this->assertNotSame(self::ACCOUNT, $stored);
        $this->assertStringNotContainsString('9170', $stored);
        $this->assertSame(self::ACCOUNT, ProjectPersonnel::find($id)->bank_account); // decrypts with the app key
        $this->assertArrayNotHasKey('bank_account', ProjectPersonnel::find($id)->toArray());

        $responses = [
            $this->actingAs($this->programLeader)->getJson($this->url())->getContent(),
            $this->actingAs($this->programLeader)->patchJson("/api/program/personnel/{$id}", $this->body(['position' => 'Project Staff Level 2']))->getContent(),
            $this->actingAs($this->projectLeader)->getJson($this->url())->getContent(),
            $this->actingAs($this->finance)->getJson($this->url())->getContent(),
            $this->actingAs($this->assistant)->getJson($this->url())->getContent(),
            $this->actingAs($this->programLeader)->getJson("/api/program/audit?project_id={$this->project->id}")->getContent(),
        ];
        foreach ($responses as $content) {
            $this->assertStringNotContainsString('1234-9170', $content);
            $this->assertStringNotContainsString('12349170', $content);
        }
        $this->assertStringNotContainsString('9170', $responses[4], 'Research Assistants do not even see the mask');
        foreach (AuditTrail::where('model_type', 'ProjectPersonnel')->get() as $entry) {
            $this->assertStringNotContainsString('1234', json_encode([$entry->old_values, $entry->new_values]));
        }
    }

    public function test_the_bank_account_is_kept_replaced_or_removed(): void
    {
        $id = $this->add();
        $body = $this->body();
        unset($body['bank_account']);
        $this->actingAs($this->programLeader)->patchJson("/api/program/personnel/{$id}", $body)->assertOk()
            ->assertJsonPath('personnel.bank_account_masked', '****9170'); // absent = kept
        $this->patchJson("/api/program/personnel/{$id}", $this->body(['bank_account' => '0000 5555 0042']))->assertOk()
            ->assertJsonPath('personnel.bank_account_masked', '****0042');
        $this->patchJson("/api/program/personnel/{$id}", $this->body(['bank_account' => null]))->assertOk()
            ->assertJsonPath('personnel.bank_account_masked', null);
    }

    // ---------------------------------------------------------------- validation

    public function test_validation(): void
    {
        $this->actingAs($this->programLeader);
        $post = fn (array $overrides) => $this->postJson("/api/program/projects/{$this->project->id}/personnel", $this->body($overrides));

        $post(['position' => '', 'special_order_no' => '', 'lib_category_id' => null, 'monthly_honorarium' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['position', 'special_order_no', 'lib_category_id', 'monthly_honorarium']);
        $post(['monthly_honorarium' => '-1'])->assertStatus(422)
            ->assertJsonPath('errors.monthly_honorarium.0', 'The monthly honorarium cannot be negative.');
        $post(['monthly_honorarium' => '100.555'])->assertStatus(422)
            ->assertJsonPath('errors.monthly_honorarium.0', 'Use at most two decimals for the monthly honorarium.');
        $post(['lib_category_id' => $this->lineItem->id])->assertStatus(422) // MOOE, not PS
            ->assertJsonPath('errors.lib_category_id.0', 'Choose a Personal Services (PS) line item of this project.');
        $post(['user_id' => $this->outsider->id])->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Choose a member of this project.');
        $post(['bank_account' => 'ABC-123'])->assertStatus(422)->assertJsonValidationErrors(['bank_account']);
        $this->assertSame(0, ProjectPersonnel::count());

        // Zero is allowed (named in payroll, never paid 0.00); the bank account is optional.
        $post(['monthly_honorarium' => '0', 'bank_account' => null])->assertCreated()->assertJsonPath('personnel.monthly_honorarium', 0);
    }

    public function test_effective_dates_stay_inside_the_project_period_and_do_not_overlap(): void
    {
        $this->actingAs($this->programLeader);
        $post = fn (array $overrides) => $this->postJson("/api/program/projects/{$this->project->id}/personnel", $this->body($overrides));

        $post(['effective_from' => '2025-12-31'])->assertStatus(422)
            ->assertJsonPath('errors.effective_from.0', 'The start date must be within the project period.');
        $post(['effective_to' => '2027-01-01'])->assertStatus(422)
            ->assertJsonPath('errors.effective_to.0', 'The end date must be within the project period.');
        $post(['effective_from' => '2026-06-01', 'effective_to' => '2026-05-31'])->assertStatus(422)->assertJsonValidationErrors(['effective_to']);
        $post(['effective_from' => '01/06/2026'])->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        // Two appointments of one person: fine when the periods do not overlap.
        $post(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30'])->assertCreated();
        $post(['effective_from' => '2026-06-01', 'effective_to' => '2026-12-31'])->assertStatus(422)
            ->assertJsonPath('errors.effective_from.0', 'This person already has a personnel record for part of this period.');
        $post(['effective_from' => '2026-07-01', 'effective_to' => '2026-12-31', 'special_order_no' => 'SO No. 2222-OP s. 2026'])->assertCreated();
        $this->assertSame(2, ProjectPersonnel::where('user_id', $this->assistant->id)->count());
    }

    // ---------------------------------------------------------------- audit

    public function test_one_audit_entry_per_change(): void
    {
        $audit = AuditTrail::count();
        $id = $this->add();
        $this->assertSame($audit + 1, AuditTrail::count());
        $created = AuditTrail::latest('id')->first();
        $this->assertSame(['created', 'ProjectPersonnel', $this->project->id], [$created->action, $created->model_type, (int) $created->project_id]);
        $this->assertSame('****9170', $created->new_values['bank_account_masked']);

        $this->actingAs($this->programLeader)->patchJson("/api/program/personnel/{$id}", $this->body(['monthly_honorarium' => '8000']))->assertOk();
        $this->assertSame($audit + 2, AuditTrail::count());
        $updated = AuditTrail::latest('id')->first();
        $this->assertSame(['monthly_honorarium' => 7500], $updated->old_values);
        $this->assertSame(['monthly_honorarium' => 8000], $updated->new_values);

        // Same values again: nothing changed, no entry.
        $this->patchJson("/api/program/personnel/{$id}", $this->body(['monthly_honorarium' => '8000.00']))->assertOk();
        $this->assertSame($audit + 2, AuditTrail::count());

        // Special Order upload and removal: one entry each.
        $file = UploadedFile::fake()->create('special-order.pdf', 20, 'application/pdf');
        $doc = $this->postJson("/api/program/personnel/{$id}/special-order", ['file' => $file])->assertCreated()->json('document.id');
        $this->assertSame($audit + 3, AuditTrail::count());
        $this->getJson($this->url())->assertJsonPath('personnel.0.special_order_document.id', $doc);
        // A record with an uploaded order cannot be removed until the file is.
        $this->deleteJson("/api/program/personnel/{$id}")->assertStatus(422)->assertJsonValidationErrors(['special_order']);
        $this->deleteJson("/api/program/personnel/{$id}/special-order")->assertOk();
        $this->assertSame($audit + 4, AuditTrail::count());

        $this->deleteJson("/api/program/personnel/{$id}")->assertOk();
        $this->assertSame($audit + 5, AuditTrail::count());
        $this->assertSame('deleted', AuditTrail::latest('id')->value('action'));
        $this->assertSame(0, ProjectPersonnel::count());
    }

    public function test_the_special_order_opens_for_full_viewers_only(): void
    {
        $id = $this->add();
        $file = UploadedFile::fake()->create('special-order.pdf', 20, 'application/pdf');
        $doc = $this->actingAs($this->programLeader)->postJson("/api/program/personnel/{$id}/special-order", ['file' => $file])->assertCreated()->json('document.id');

        foreach ([$this->programLeader, $this->projectLeader, $this->finance] as $user) {
            $this->actingAs($user)->get("/api/documents/{$doc}/download")->assertOk();
        }
        foreach ([$this->assistant, $this->researcher, $this->outsider] as $user) {
            $this->actingAs($user)->get("/api/documents/{$doc}/download")->assertForbidden();
        }
        // Not listed among the project's documents (Research Assistant page).
        $this->actingAs($this->assistant)->getJson("/api/projects/{$this->project->id}/documents")->assertOk()
            ->assertJsonMissing(['id' => $doc]);
        // A second upload while one exists is refused.
        $this->actingAs($this->programLeader)->postJson("/api/program/personnel/{$id}/special-order", ['file' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    // ---------------------------------------------------------------- migration

    public function test_old_pay_rates_become_personnel_records(): void
    {
        AuditLogger::muted(function () {
            ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->researcher->id)
                ->update(['monthly_rate' => 30000, 'allowance' => 1500]);
            ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->assistant->id)
                ->update(['monthly_rate' => 20000, 'allowance' => 0]);
            // A project with no PS line item.
            $other = $this->makeProject('NO-PS-001', 'Placeholder project without PS');
            $this->assignTo($this->researcher, $other, 'researcher');
            ProjectUser::where('project_id', $other->id)->update(['monthly_rate' => 10000]);
            // Already migrated: skipped (safe to rerun).
            $this->setHonorarium($this->finance, 5000);
            ProjectUser::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->update(['monthly_rate' => 99999]);
        });

        if (! class_exists(\CreateProjectPersonnelTable::class, false)) {
            require_once database_path('migrations/2026_11_25_000001_create_project_personnel_table.php');
        }
        $report = (new \CreateProjectPersonnelTable())->migratePayRates();

        $this->assertSame(['migrated' => 3, 'skipped_existing' => 1, 'without_ps_line_item' => 1, 'allowances_not_carried' => 1], $report);
        $researcher = ProjectPersonnel::where('project_id', $this->project->id)->where('user_id', $this->researcher->id)->firstOrFail();
        $this->assertSame(['Researcher', $this->ps->id, '2026-01-01', '2026-12-31', '30000.00', null], [
            $researcher->position, (int) $researcher->lib_category_id, $researcher->effective_from->toDateString(),
            $researcher->effective_to->toDateString(), $researcher->monthly_honorarium, $researcher->special_order_no,
        ]);
        $this->assertSame('Research Assistant', ProjectPersonnel::where('user_id', $this->assistant->id)->value('position'));
        $this->assertNull(ProjectPersonnel::where('user_id', $this->researcher->id)->where('project_id', '!=', $this->project->id)->value('lib_category_id'));
        $this->assertSame('5000.00', ProjectPersonnel::where('user_id', $this->finance->id)->value('monthly_honorarium'));

        // Rerun: nothing new.
        $this->assertSame(0, (new \CreateProjectPersonnelTable())->migratePayRates()['migrated']);
        // The legacy columns are left as they were (additive).
        $this->assertSame(1500.0, (float) DB::table('project_user')->where('user_id', $this->researcher->id)->where('project_id', $this->project->id)->value('allowance'));
    }
}
