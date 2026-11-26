<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Audit log search box: `q` on /api/program/audit (Program Leader) and
 * /api/audit (Project Leader, Finance/Admin), searched on the server.
 */
class AuditSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;
    private User $encoder;
    private Project $weaving;
    private Project $fisheries;
    private LibCategory $travel;
    private LibCategory $supplies;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-20 02:00:00', 'UTC'));

        AuditLogger::muted(function () {
            $this->leader = $this->person('program-leader', 'Dr. Rahana Bayabao');
            $this->encoder = $this->person('research-assistant', 'Amina Lucman');
            $this->weaving = $this->project('WV-2026-001', 'Meranaw Weaving Heritage Archive');
            $this->fisheries = $this->project('FS-2026-002', 'Lake Lanao Fisheries Study');
            $this->travel = $this->lineItem($this->weaving, 'Traveling Expenses');
            $this->supplies = $this->lineItem($this->fisheries, 'Office Supplies');
        });

        // Weaving: the encoder adds a line item; the leader verifies it.
        Carbon::setTestNow('2026-11-10 02:00:00');
        AuditTrail::record($this->encoder, 'created', $this->travel, [], ['project_id' => $this->weaving->id, 'line_item_name' => 'Traveling Expenses']);
        Carbon::setTestNow('2026-11-12 02:00:00');
        AuditTrail::record($this->leader, 'verified', $this->travel, ['status' => 'pending'], ['status' => 'verified', 'project_id' => $this->weaving->id]);
        // Fisheries: the leader adds a line item.
        Carbon::setTestNow('2026-11-15 02:00:00');
        AuditTrail::record($this->leader, 'created', $this->supplies, [], ['project_id' => $this->fisheries->id, 'line_item_name' => 'Office Supplies']);
        Carbon::setTestNow('2026-11-20 02:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_q_matches_the_user_name_case_insensitively(): void
    {
        $feed = $this->programFeed(['q' => 'aMiNa']);

        $this->assertSame(1, $feed['meta']['total']);
        $this->assertSame('Amina Lucman', $feed['entries'][0]['actor']['name']);
    }

    public function test_q_matches_the_record_name_and_record_type(): void
    {
        $this->assertSame(2, $this->programFeed(['q' => 'traveling'])['meta']['total']);
        $this->assertSame(1, $this->programFeed(['q' => 'office supp'])['meta']['total']);
        // "Line item" is the type label of LibCategory.
        $this->assertSame(3, $this->programFeed(['q' => 'line item'])['meta']['total']);
    }

    public function test_q_matches_the_action_as_the_page_words_it(): void
    {
        $verified = $this->programFeed(['q' => 'verified']);
        $this->assertSame(1, $verified['meta']['total']);
        $this->assertSame('Line item verified', $verified['entries'][0]['action']['label']);

        // "Added" is the label of "created" ("Line item added").
        $this->assertSame(2, $this->programFeed(['q' => 'item added'])['meta']['total']);
    }

    public function test_q_matches_the_project_code_and_title(): void
    {
        $this->assertSame(2, $this->programFeed(['q' => 'wv-2026'])['meta']['total']);
        $this->assertSame(1, $this->programFeed(['q' => 'Lanao'])['meta']['total']);
    }

    public function test_q_combines_with_the_other_filters_and_paging_meta(): void
    {
        $feed = $this->programFeed(['q' => 'line item', 'user' => $this->leader->id, 'from' => '2026-11-13']);
        $this->assertSame(1, $feed['meta']['total']);
        $this->assertSame('Office Supplies', $feed['entries'][0]['record']['label']);
        $this->assertFalse($feed['meta']['has_more']);

        $this->assertSame(1, $this->programFeed(['q' => 'line item', 'action' => 'verified'])['meta']['total']);
        $this->assertSame(0, $this->programFeed(['q' => 'line item', 'project' => $this->weaving->id, 'action' => 'created', 'user' => $this->leader->id])['meta']['total']);
    }

    public function test_q_with_no_match_returns_nothing_and_special_characters_are_literal(): void
    {
        $this->assertSame(0, $this->programFeed(['q' => 'no such thing'])['meta']['total']);
        $this->assertSame(0, $this->programFeed(['q' => '%'])['meta']['total']);
        $this->assertSame(0, $this->programFeed(['q' => '_'])['meta']['total']);
        // A blank search is no search.
        $this->assertSame(3, $this->programFeed(['q' => '  '])['meta']['total']);
    }

    public function test_project_leader_and_finance_search_only_their_own_projects(): void
    {
        foreach (['project-leader', 'finance-admin'] as $slug) {
            $person = AuditLogger::muted(function () use ($slug) {
                $person = $this->person($slug, 'Scoped ' . $slug);
                ProjectUser::create([
                    'project_id' => $this->weaving->id,
                    'user_id' => $person->id,
                    'role_id' => Role::where('slug', $slug)->value('id'),
                ]);

                return $person;
            });

            // "Line item" matches all three entries; only Weaving's two are theirs.
            $rows = $this->actingAs($person)->getJson('/api/audit?q=line%20item')->assertOk()->json();
            $this->assertCount(2, $rows, $slug);
            $this->assertSame(['Meranaw Weaving Heritage Archive'], array_values(array_unique(array_column($rows, 'project'))), $slug);
            $this->assertSame('WV-2026-001', $rows[0]['project_code'], $slug);

            // The other project's entries never appear, even when searched by name.
            $this->assertCount(0, $this->actingAs($person)->getJson('/api/audit?q=Lanao')->assertOk()->json(), $slug);
            $this->assertCount(1, $this->actingAs($person)->getJson('/api/audit?q=amina')->assertOk()->json(), $slug);
        }
    }

    public function test_the_audit_feed_rejects_an_overlong_search(): void
    {
        $this->actingAs($this->leader)->getJson('/api/program/audit?q=' . str_repeat('a', 101))->assertStatus(422);
        $this->actingAs($this->leader)->getJson('/api/audit?q=' . str_repeat('a', 101))->assertStatus(422);
    }

    // -------------------------------------------------------------- helpers

    private function programFeed(array $query): array
    {
        return $this->actingAs($this->leader)
            ->getJson('/api/program/audit?' . http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function project(string $code, string $title): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => $code,
            'title' => $title,
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }

    private function lineItem(Project $project, string $name): LibCategory
    {
        return LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => $name,
            'allocated_amount' => 100000,
        ]);
    }

    private function person(string $slug, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
