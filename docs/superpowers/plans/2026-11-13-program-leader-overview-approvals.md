# Program Leader Overview & Approvals Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish the Program Leader shared shell, Overview, and Approvals pages while preserving existing auth, endpoints, calculations, and passing tests.

**Architecture:** Bring the working branch up to the current `origin/main` Program Leader UI, then extend the existing `ProgramDashboardService` and `ProgramApprovalController` with one details endpoint, reviewer snapshots, waiting-level metadata, decision notifications, and a daily reminder command. The frontend keeps the existing React pages and CSS token system; changes are additive and reuse the existing approval list/decision endpoints.

**Tech Stack:** Laravel 8 / PHP 8, MySQL (SQLite in tests), React 19, Vite, React Router, axios, plain CSS tokens.

**Spec:** User brief in this session dated 2026-11-13 (Program Leader layout, Overview, and Approvals polish). The brief's exact copy, token values, and test list are reproduced in the tasks below.

## Global Constraints

- Work from `origin/main` (`05ff4f6`), not the current stale `feature/program-leader-dashboard` (`d904321`). The requested Overview UI exists only on `origin/main`.
- Use only `/images/msu-seal-outline.png` and `/images/client-logo-outline.png`. They exist and are real assets: 317×324 and 384×346. Never redraw or generate logos.
- Reuse these tokens exactly: brand `#6F1D2A`, brand-dark `#511420`, page `#FAF7F2`, surface `#EDE4D6`, gold `#BE9A3E`, ink `#2B2023`, muted `#6B5F5F`, input border `#D6CBBB`, success `#1E5A37`/`#DDF0E4`, warning `#92400E`/`#FEF3C7`, error `#8E1B1B`/`#FDECEC`, neutral `#4A4040`/`#ECE7DD`.
- Status is always text plus icon, never color alone.
- Every button/tab is at least 44px tall.
- Every focusable control uses `outline: 3px solid rgba(111,29,42,.4); outline-offset: 2px`.
- Never drop or rename existing columns or delete data. New migrations are additive.
- Keep existing auth, role middleware, routes, and financial calculations. Do not change `ProjectFinancialService` numbers.
- No approval-request creation form or API exists in this checkout. Add the `justification` column and enforce it on new `ApprovalRequest` model creations; do not invent a request form.
- No documents table or upload/download system exists. Use the existing path columns (`Liquidation.document_path`, `ApprovalRequest.payload.attachments`) through a read-only attachment resolver. Do not build an upload subsystem.
- Mail is considered configured only when the default mailer is not `log`/`array`, a from address exists, and SMTP has a host. Decisions and reminders must still succeed when mail is skipped.
- The final report must list changed files, Render migrations, scheduler setup, new env vars, and the missing New project items.

## Review Focus

- Existing production approval rows have `justification = NULL`; details must show “No justification provided”, not crash or hide the request.
- Seeded attachment paths point at files that do not exist; the UI must show “File unavailable”, not a broken download.
- A request may have no requester (nullable `requested_by`); details and notifications must degrade safely.
- A realignment payload may reference deleted or cross-project line items; impact must return `null` rather than 500.
- A notification/mail failure must not roll back or fail a committed decision.
- At 390px, long titles, amounts, waiting pills, and details tables must wrap rather than overflow the viewport.

---

### Task 0: Start from the current Program Leader UI

**Files:** none.

**Interfaces:**
- Consumes: clean working tree at `d904321`.
- Produces: branch containing `origin/main`’s Program Leader layout and Overview UI.

- [ ] **Step 1: Confirm the working tree is clean**

Run: `git status --short`
Expected: no output.

- [ ] **Step 2: Create the polish branch from `origin/main`**

Run: `git switch -c feature/program-leader-polish origin/main`
Expected: `Switched to a new branch 'feature/program-leader-polish'` and `git log -1 --oneline` starts with `05ff4f6`.

Alternative if the user prefers to keep the current branch: `git merge --ff-only origin/main`.

- [ ] **Step 3: Verify the logo assets before touching UI code**

Run:
```powershell
Add-Type -AssemblyName System.Drawing
foreach ($n in @('msu-seal-outline.png','client-logo-outline.png')) {
  $p = "frontend/public/images/$n"
  $img = [System.Drawing.Image]::FromFile((Resolve-Path $p))
  "$n $($img.Width)x$($img.Height) $((Get-Item $p).Length) bytes"
  $img.Dispose()
}
```
Expected: `msu-seal-outline.png 317x324` and `client-logo-outline.png 384x346`, both non-trivial file sizes. Stop if missing or placeholder.

---

### Task 1: Add justification and reviewer snapshots

**Files:**
- Create: `backend/database/migrations/2026_11_13_000001_add_approval_details_to_approval_requests_table.php`
- Modify: `backend/app/Models/ApprovalRequest.php`
- Modify: `backend/database/seeders/DashboardDemoSeeder.php`
- Modify: `backend/database/seeders/DemoDatasetSeeder.php`
- Modify: `backend/database/seeders/ProgramDashboardSeeder.php`
- Modify: `backend/tests/Feature/AuditTrailTest.php`
- Modify: `backend/tests/Feature/MakerCheckerTest.php`
- Modify: `backend/tests/Feature/ProgramApprovalsTest.php`
- Modify: `backend/tests/Feature/ProgramDashboardTest.php`
- Test: `backend/tests/Feature/ProgramApprovalsTest.php`

**Interfaces:**
- Consumes: existing `approval_requests` table.
- Produces: nullable `justification` text column; nullable `reviewed_by_name` and `reviewed_by_role` string columns; `ApprovalRequest::$fillable` includes all three; new `ApprovalRequest` creations require a non-empty `justification`.

- [ ] **Step 1: Write the failing test**

Add to `ProgramApprovalsTest`:
```php
public function test_new_approval_requests_require_a_justification(): void
{
    $project = $this->project();

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $this->expectExceptionMessage('A justification is required for new approval requests.');

    ApprovalRequest::create([
        'project_id' => $project->id,
        'type' => 'disbursement',
        'title' => 'No justification',
        'amount' => 10000,
        'requested_by' => $this->userWithRole('project-leader')->id,
        'status' => 'pending',
    ]);
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_new_approval_requests_require_a_justification`
Expected: FAIL because no validation exists.

- [ ] **Step 3: Add the migration and model guard**

Migration `up()`:
```php
Schema::table('approval_requests', function (Blueprint $table) {
    $table->text('justification')->nullable()->after('description');
    $table->string('reviewed_by_name')->nullable()->after('reviewed_by');
    $table->string('reviewed_by_role')->nullable()->after('reviewed_by_name');
});
```
`down()` drops the three columns.

In `ApprovalRequest`:
- Add `justification`, `reviewed_by_name`, `reviewed_by_role` to `$fillable`.
- Add:
```php
protected static function booted(): void
{
    static::creating(function (self $approval) {
        if (blank($approval->justification)) {
            throw ValidationException::withMessages([
                'justification' => ['A justification is required for new approval requests.'],
            ]);
        }
    });
}
```
- Import `Illuminate\Validation\ValidationException`.

- [ ] **Step 4: Update every seed/test creation path**

Add a non-empty `justification` to every `ApprovalRequest::create()` and `ApprovalRequest::updateOrCreate()` call in the seeders and test helpers listed above. Existing database rows are not backfilled; their `justification` stays `NULL`.

Change the test helper to accept overrides so later tasks can build realistic payloads:
```php
private function approval(
    Project $project,
    string $type,
    string $status,
    array $overrides = []
): ApprovalRequest {
    return ApprovalRequest::create(array_merge([
        'project_id' => $project->id,
        'type' => $type,
        'title' => ucfirst(str_replace('_', ' ', $type)) . ' request',
        'description' => 'Test approval request',
        'justification' => 'Test justification',
        'amount' => 10000,
        'requested_by' => $this->userWithRole('project-leader')->id,
        'status' => $status,
    ], $overrides));
}
```
For the “old row without justification” details test, create the row with `ApprovalRequest::withoutEvents(fn () => ApprovalRequest::create([...]))` and assert the details endpoint returns `justification = null`.

- [ ] **Step 5: Run the focused test and the existing approval tests**

Run: `php artisan test --filter=ProgramApprovalsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_11_13_000001_add_approval_details_to_approval_requests_table.php backend/app/Models/ApprovalRequest.php backend/database/seeders backend/tests/Feature
git commit -m "feat: add approval justification and reviewer snapshots"
```

---

### Task 2: Add waiting levels, reviewer snapshots, and audit remarks

**Files:**
- Modify: `backend/app/Services/ProgramDashboardService.php`
- Test: `backend/tests/Feature/ProgramApprovalsTest.php`
- Test: `backend/tests/Feature/ProgramDashboardTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces:
  - `ProgramDashboardService::waitLevel(int $days): string` returns `normal` for ≤7, `warning` for 8–14, `critical` for >14.
  - `approvalListRow()` and `approvalRows()` return `wait_level`.
  - `approvalListRow()` returns `reviewed_by_role` and uses `reviewed_by_name`/`reviewed_by_role` snapshots first.
  - `decide()` writes `reviewed_by_name`, `reviewed_by_role`, and `reason => $remarks` on the decision audit row.

- [ ] **Step 1: Write the failing boundary test**

Add to `ProgramApprovalsTest`:
```php
public function test_waiting_levels_use_seven_and_fourteen_day_boundaries(): void
{
    $leader = $this->userWithRole('program-leader');
    $project = $this->project();

    foreach ([7, 8, 14, 15] as $days) {
        $approval = $this->approval($project, 'disbursement', 'pending');
        $approval->forceFill(['created_at' => now()->subDays($days)])->save();
    }

    $response = $this->actingAs($leader)
        ->getJson('/api/program/approvals?status=pending')
        ->assertOk();

    $levels = collect($response->json('approvals'))->pluck('wait_level', 'days_waiting');
    $this->assertSame('normal', $levels[7]);
    $this->assertSame('warning', $levels[8]);
    $this->assertSame('warning', $levels[14]);
    $this->assertSame('critical', $levels[15]);
}
```
The test reads the API row, not a model accessor. Do not add a `wait_level` model accessor.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_waiting_levels_use_seven_and_fourteen_day_boundaries`
Expected: FAIL because `wait_level` is absent.

- [ ] **Step 3: Implement the helper and row fields**

```php
public static function waitLevel(int $days): string
{
    if ($days > 14) return 'critical';
    if ($days > 7) return 'warning';
    return 'normal';
}
```
Add `'wait_level' => self::waitLevel($daysWaiting)` to both approval row builders. Add `'reviewed_by_role' => $approval->reviewed_by_role ?? $approval->reviewedBy?->primaryRole?->name` to `approvalListRow()`. Eager-load `reviewedBy.primaryRole` in `approvalList()`.

- [ ] **Step 4: Persist reviewer snapshots and audit remarks**

In `decide()`, set:
```php
'reviewed_by_name' => $actor->name,
'reviewed_by_role' => $actor->primaryRole?->name,
```
Add `'reason' => $remarks` to the final `AuditTrail::create()` row. Add a test assertion:
```php
$this->assertDatabaseHas('approval_requests', [
    'id' => $approval->id,
    'reviewed_by_name' => $programLeader->name,
    'reviewed_by_role' => 'Program Leader',
]);
$this->assertDatabaseHas('audit_trails', [
    'model_id' => $approval->id,
    'action' => 'approved',
    'reason' => 'Approved for field travel.',
]);
```

- [ ] **Step 5: Pin decision validation and maker-checker behavior**

Add these tests to `ProgramApprovalsTest` (keep the existing `MakerCheckerTest` coverage):
```php
public function test_approving_without_remarks_is_allowed_but_rejecting_requires_them(): void
{
    $leader = $this->userWithRole('program-leader');
    $project = $this->project();

    $approve = $this->approval($project, 'disbursement', 'pending');
    $this->actingAs($leader)
        ->postJson("/api/program/approvals/{$approve->id}/decision", ['decision' => 'approved'])
        ->assertOk();

    $reject = $this->approval($project, 'disbursement', 'pending');
    $this->actingAs($leader)
        ->postJson("/api/program/approvals/{$reject->id}/decision", ['decision' => 'rejected'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('remarks');
}

public function test_a_program_leader_cannot_decide_their_own_request(): void
{
    $leader = $this->userWithRole('program-leader');
    $approval = $this->approval($this->project(), 'disbursement', 'pending', [
        'requested_by' => $leader->id,
    ]);

    $this->actingAs($leader)
        ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])
        ->assertForbidden();
}
```

- [ ] **Step 6: Run the approval/dashboard tests**

Run: `php artisan test --filter='ProgramApprovalsTest|ProgramDashboardTest|MakerCheckerTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/ProgramDashboardService.php backend/tests/Feature
git commit -m "feat: expose approval waiting levels and reviewer snapshots"
```

---

### Task 3: Add the Program Leader approval details endpoint

**Files:**
- Create: `backend/app/Services/ApprovalDetailsService.php`
- Modify: `backend/app/Http/Controllers/ProgramApprovalController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/config/program.php` (create if absent)
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/ProgramApprovalsTest.php`

**Interfaces:**
- Consumes: `ApprovalRequest`, `ProjectFinancialService`, `Liquidation`, `LibCategory`, `Expense`, `Storage`.
- Produces:
  - `GET /api/program/approvals/{approval}` → details JSON, Program Leader only.
  - `GET /api/program/approvals/{approval}/attachments/{index}` → streamed download, Program Leader only.
  - `ApprovalDetailsService::details(ApprovalRequest $approval): array`
  - `ApprovalDetailsService::attachment(ApprovalRequest $approval, int $index): ?array` returns `['path' => string, 'name' => string]`.
  - Details keys: `id, kind, type, type_label, title, description, project, project_code, funder, amount, status, requester{name,role}, requested_at, days_waiting, wait_level, justification, attachments[], impact|null, fund_release|null, liquidation|null, review_notes, reviewed_by{name,role}, reviewed_at`.
  - `impact` keys: `source`, `destination` (each `id,name,allocated,disbursed,balance_before,balance_after`), `amount`, `share_pct`, `threshold_pct`, `above_threshold`.
  - `fund_release` keys: `tranche_name, amount, target_date, days_overdue, previous_tranche_liquidated, project_receipt_compliance_pct, project_receipts_count, project_expenses_count`.
  - `liquidation` keys: `amount, expenses_with_receipt, expenses_without_receipt, expenses_total, expense_scope, tranche`.
  - `attachments[]` keys: `index, name, type, size, available`.

- [ ] **Step 1: Write the failing details/403 tests**

Add:
```php
public function test_details_endpoint_is_forbidden_for_non_program_leaders(): void
{
    $approval = $this->approval($this->project(), 'budget_realignment', 'pending');

    $this->actingAs($this->userWithRole('project-leader'))
        ->getJson("/api/program/approvals/{$approval->id}")
        ->assertForbidden();
}
```
Add `test_realignment_impact_numbers_and_threshold_flag`, `test_fund_release_details_show_previous_liquidation_and_receipt_compliance`, `test_liquidation_details_count_receipts`, `test_old_requests_show_null_justification`, and `test_attachment_download_streams_only_request_documents`.

Key impact assertions:
```php
$source = $this->category($project, 500000);
$target = $this->category($project, 100000);
$approval = $this->approval($project, 'budget_realignment', 'pending', [
    'amount' => 40000,
    'payload' => [
        'from_lib_category_id' => $source->id,
        'to_lib_category_id' => $target->id,
        'amount' => 40000,
    ],
]);

$response = $this->actingAs($leader)
    ->getJson("/api/program/approvals/{$approval->id}")
    ->assertOk();

$this->assertSame(500000.0, (float) $response->json('impact.source.allocated'));
$this->assertSame(400000.0, (float) $response->json('impact.source.balance_before'));
$this->assertSame(360000.0, (float) $response->json('impact.source.balance_after'));
$this->assertSame(140000.0, (float) $response->json('impact.destination.balance_after'));
$this->assertSame(8.0, (float) $response->json('impact.share_pct'));
$this->assertFalse($response->json('impact.above_threshold'));
```
Then set the amount to `50000` and assert `share_pct = 10.0` and `above_threshold = true`.

Fund-release assertions:
```php
$first = ProjectTranche::create([
    'project_id' => $project->id, 'sequence' => 1, 'name' => 'Tranche 1',
    'amount' => 100000, 'percentage' => 20, 'status' => 'released',
    'planned_release_date' => now()->subMonths(2)->toDateString(),
]);
$second = ProjectTranche::create([
    'project_id' => $project->id, 'sequence' => 2, 'name' => 'Tranche 2',
    'amount' => 200000, 'percentage' => 40, 'status' => 'pending',
    'planned_release_date' => now()->subDays(10)->toDateString(),
]);
Liquidation::create([
    'project_id' => $project->id, 'project_tranche_id' => $first->id,
    'title' => 'Tranche 1 liquidation', 'amount' => 90000,
    'status' => 'approved', 'submitted_at' => now()->subMonth()->toDateString(),
]);
$approval = $this->approval($project, 'disbursement', 'pending', [
    'project_tranche_id' => $second->id, 'amount' => 200000,
]);

$response = $this->actingAs($leader)
    ->getJson("/api/program/approvals/{$approval->id}")
    ->assertOk();

$this->assertSame('Tranche 2', $response->json('fund_release.tranche_name'));
$this->assertSame(10, $response->json('fund_release.days_overdue'));
$this->assertTrue($response->json('fund_release.previous_tranche_liquidated'));
$this->assertIsNumeric($response->json('fund_release.project_receipt_compliance_pct'));
```

Liquidation assertions:
```php
$tranche = ProjectTranche::create([
    'project_id' => $project->id, 'sequence' => 1, 'name' => 'Tranche 1',
    'amount' => 100000, 'percentage' => 20, 'status' => 'released',
]);
$liquidation = Liquidation::create([
    'project_id' => $project->id, 'project_tranche_id' => $tranche->id,
    'title' => 'Liquidation 1', 'amount' => 25000, 'status' => 'submitted',
]);
$category = $this->category($project, 500000);
$recorder = $this->userWithRole('project-leader');
Expense::create([
    'project_id' => $project->id, 'lib_category_id' => $category->id,
    'recorded_by' => $recorder->id, 'description' => 'With receipt',
    'amount' => 1000, 'expense_date' => now()->toDateString(),
    'attachment_path' => 'receipts/a.pdf', 'status' => 'verified',
]);
Expense::create([
    'project_id' => $project->id, 'lib_category_id' => $category->id,
    'recorded_by' => $recorder->id, 'description' => 'Without receipt',
    'amount' => 2000, 'expense_date' => now()->toDateString(),
    'attachment_path' => null, 'status' => 'verified',
]);
$approval = $this->approval($project, 'liquidation', 'pending', [
    'liquidation_id' => $liquidation->id, 'amount' => 25000,
]);

$response = $this->actingAs($leader)
    ->getJson("/api/program/approvals/{$approval->id}")
    ->assertOk();

$this->assertSame(1, $response->json('liquidation.expenses_with_receipt'));
$this->assertSame(1, $response->json('liquidation.expenses_without_receipt'));
$this->assertSame(2, $response->json('liquidation.expenses_total'));
$this->assertSame('project', $response->json('liquidation.expense_scope'));
$this->assertSame('Tranche 1', $response->json('liquidation.tranche'));
```

Old-row assertion:
```php
$old = ApprovalRequest::withoutEvents(fn () => ApprovalRequest::create([
    'project_id' => $project->id,
    'type' => 'disbursement',
    'title' => 'Legacy request',
    'amount' => 10000,
    'requested_by' => $this->userWithRole('project-leader')->id,
    'status' => 'pending',
]));

$this->actingAs($leader)
    ->getJson("/api/program/approvals/{$old->id}")
    ->assertOk()
    ->assertJsonPath('justification', null);
```

Add `use App\Models\Expense;`, `use App\Models\Liquidation;`, `use App\Models\ProjectTranche;`, and `use Illuminate\Support\Facades\Storage;` to the test file.

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test --filter='test_details_endpoint_is_forbidden_for_non_program_leaders|test_realignment_impact_numbers_and_threshold_flag'`
Expected: FAIL with 404 or missing keys.

- [ ] **Step 3: Add config and routes**

Create `backend/config/program.php`:
```php
return [
    'realignment_warning_pct' => (float) env('PROGRAM_REALIGNMENT_WARNING_PCT', 10),
];
```
Add to `.env.example`: `PROGRAM_REALIGNMENT_WARNING_PCT=10`.

Inside the existing `role.program-leader` group in `backend/routes/api.php`:
```php
Route::get('/program/approvals/{approval}', [ProgramApprovalController::class, 'show']);
Route::get('/program/approvals/{approval}/attachments/{index}', [ProgramApprovalController::class, 'attachment']);
```

- [ ] **Step 4: Implement `ApprovalDetailsService`**

- Load `project.fundingAgency`, `project.libCategories`, `project.expenses`, `project.tranches`, `requestedBy.primaryRole`, `reviewedBy.primaryRole`, `tranche`, `liquidation.tranche`.
- Reuse `ProgramDashboardService::waitLevel()`.
- Realignment: resolve source/target by `project_id` and payload IDs; use `LibCategory::spentAmount()` for disbursed; return `null` impact if source, target, or amount is missing.
- Fund release: compute `days_overdue` from `planned_release_date`; previous tranche is the highest `sequence` below the current one; `previous_tranche_liquidated` is true only when that tranche has an `approved` liquidation; receipt compliance from `ProjectFinancialService::financials($project)`.
- Liquidation: use `payload['expense_ids']` when present, otherwise the project’s expenses; count `filled($expense->attachment_path)` vs blank; set `expense_scope` to `request` or `project`; show `liquidation->tranche?->name`.
- Attachments: collect `liquidation.document_path` and `payload['attachments']` (strings or `{name,path,type}` objects); derive name/type; `available` is true only when `Storage::disk('public')->exists($path)`; size is `Storage::disk('public')->size($path)` when available.
- `attachment()` returns the path/name for the requested zero-based index or `null`.

- [ ] **Step 5: Implement controller methods**

`show()` returns `response()->json($this->details->details($approval))`.
`attachment()` calls the resolver; abort 404 when null or missing; otherwise `return Storage::disk('public')->download($path, $name);`.

- [ ] **Step 6: Add attachment tests**

Use `Storage::fake('public')`, `Storage::disk('public')->put('receipts/test.pdf', 'receipt-data')`, and a liquidation with `document_path = 'receipts/test.pdf'`. Assert:
- details returns one attachment with `available = true`;
- `GET /api/program/approvals/{id}/attachments/0` returns 200 and the file;
- a project-leader gets 403;
- a missing file returns 404;
- a request with no paths returns `attachments = []`.

- [ ] **Step 7: Run the approval tests**

Run: `php artisan test --filter=ProgramApprovalsTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/ApprovalDetailsService.php backend/app/Http/Controllers/ProgramApprovalController.php backend/routes/api.php backend/config/program.php backend/.env.example backend/tests/Feature/ProgramApprovalsTest.php
git commit -m "feat: add approval details and attachment endpoints"
```

---

### Task 4: Notify requesters and expose in-app notifications

**Files:**
- Create: `backend/database/migrations/2026_11_13_000002_create_notifications_table.php`
- Create: `backend/app/Support/MailConfiguration.php`
- Create: `backend/app/Notifications/ApprovalDecisionNotification.php`
- Create: `backend/app/Http/Controllers/NotificationController.php`
- Modify: `backend/app/Services/ProgramDashboardService.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ProgramApprovalsTest.php`
- Test: `backend/tests/Feature/NotificationTest.php` (create)

**Interfaces:**
- Consumes: Task 1 reviewer snapshots; existing `User` `Notifiable` trait.
- Produces:
  - `MailConfiguration::isConfigured(): bool`.
  - `ApprovalDecisionNotification(ApprovalRequest $approval, string $decision, ?string $remarks, string $deciderName, string $deciderRole)`.
  - `GET /api/notifications` → `{ notifications: [...], unread_count: int }` for the current user only.
  - `POST /api/notifications/{id}/read` → marks one owned notification read.
  - `POST /api/notifications/read-all` → marks all owned notifications read.
  - Decision notification is sent after commit and failure is logged, never rolled back.

- [ ] **Step 1: Write the failing notification tests**

Add imports for `App\Notifications\ApprovalDecisionNotification`, `Illuminate\Support\Facades\Notification`, and `App\Models\User`.

```php
public function test_decision_notifies_the_requester(): void
{
    Notification::fake();
    $leader = $this->userWithRole('program-leader');
    $requester = $this->userWithRole('project-leader');
    $approval = $this->approval($this->project(), 'disbursement', 'pending', [
        'requested_by' => $requester->id,
    ]);

    $this->actingAs($leader)
        ->postJson("/api/program/approvals/{$approval->id}/decision", [
            'decision' => 'approved',
            'remarks' => 'Proceed with the release.',
        ])
        ->assertOk();

    Notification::assertSentTo($requester, ApprovalDecisionNotification::class, function ($notification, $channels) {
        return in_array('database', $channels, true);
    });
}
```
Add a separate test without `Notification::fake()`:
```php
public function test_decision_stores_an_in_app_notification(): void
{
    $leader = $this->userWithRole('program-leader');
    $requester = $this->userWithRole('project-leader');
    $approval = $this->approval($this->project(), 'disbursement', 'pending', [
        'requested_by' => $requester->id,
    ]);

    $this->actingAs($leader)
        ->postJson("/api/program/approvals/{$approval->id}/decision", [
            'decision' => 'rejected',
            'remarks' => 'Missing receipts.',
        ])
        ->assertOk();

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $requester->id,
        'notifiable_type' => User::class,
    ]);
}
```
Add a test that `MailConfiguration::isConfigured()` is false for `log` and `array`, true for SMTP with a host and from address. Add a test that `/api/notifications` returns only the current user’s rows.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter='test_decision_notifies_the_requester|test_notifications_endpoint_only_returns_current_users_notifications'`
Expected: FAIL with missing table/class/routes.

- [ ] **Step 3: Add the notifications table and mail helper**

Use Laravel’s standard database notifications schema:
```php
$table->uuid('id')->primary();
$table->string('type');
$table->morphs('notifiable');
$table->text('data');
$table->timestamp('read_at')->nullable();
$table->timestamps();
```
`MailConfiguration::isConfigured()`:
```php
$default = config('mail.default');
if (blank($default) || in_array($default, ['log', 'array'], true)) return false;
if (blank(config('mail.from.address'))) return false;
if ($default === 'smtp' && blank(config('mail.mailers.smtp.host'))) return false;
return true;
```

- [ ] **Step 4: Implement the notification**

`ApprovalDecisionNotification::via()` returns `['database']` plus `'mail'` only when `MailConfiguration::isConfigured()`.
`toArray()` returns:
```php
[
    'title' => $this->decision === 'approved' ? 'Approval request approved' : 'Approval request rejected',
    'body' => $this->approval->title . ($this->remarks ? ' — ' . $this->remarks : ''),
    'url' => '/program-approvals',
    'approval_id' => $this->approval->id,
    'decision' => $this->decision,
    'remarks' => $this->remarks,
]
```
`toMail()` uses subject `Approval request {decision}` and includes the request title, decision, decider name/role, and remarks.

- [ ] **Step 5: Send after the decision commits**

In `ProgramDashboardService::decide()`, after the transaction:
```php
$decided = $approval->fresh()->load(['requestedBy', 'tranche', 'liquidation']);
if ($decided->requestedBy) {
    try {
        $decided->requestedBy->notify(new ApprovalDecisionNotification(
            $decided,
            $decision,
            $remarks,
            $actor->name,
            $actor->primaryRole?->name ?? 'Program Leader'
        ));
    } catch (\Throwable $e) {
        report($e);
    }
}
return $decided;
```

- [ ] **Step 6: Implement the notification API**

`NotificationController@index` returns the latest 10 notifications and unread count for `$request->user()`.
`@read` uses `$request->user()->notifications()->whereKey($id)->firstOrFail()` then `markAsRead()`.
`@readAll` calls `$request->user()->unreadNotifications->markAsRead()`.
Add routes inside the authenticated group (not necessarily the Program Leader-only group).

- [ ] **Step 7: Run the notification and approval tests**

Run: `php artisan test --filter='ProgramApprovalsTest|NotificationTest'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_11_13_000002_create_notifications_table.php backend/app/Support/MailConfiguration.php backend/app/Notifications/ApprovalDecisionNotification.php backend/app/Http/Controllers/NotificationController.php backend/app/Services/ProgramDashboardService.php backend/routes/api.php backend/tests/Feature
git commit -m "feat: notify requesters and expose in-app notifications"
```

---

### Task 5: Add the daily waiting-approval reminder

**Files:**
- Create: `backend/app/Notifications/ApprovalWaitingDigestNotification.php`
- Create: `backend/app/Console/Commands/SendApprovalWaitingDigest.php`
- Modify: `backend/app/Console/Kernel.php`
- Modify: `DEPLOYMENT.md`
- Test: `backend/tests/Feature/NotificationTest.php`

**Interfaces:**
- Consumes: Task 4 `MailConfiguration`, notifications table, `ApprovalRequest`.
- Produces:
  - Command signature `program:approval-reminders`.
  - `ApprovalWaitingDigestNotification(Collection $approvals, int $count)`.
  - Daily schedule at 08:00; cache key `program-approval-digest:YYYY-MM-DD` ensures one digest per day.
  - Render Cron Job documentation for `php artisan schedule:run` every minute.

- [ ] **Step 1: Write the failing reminder tests**

Add imports for `App\Notifications\ApprovalWaitingDigestNotification`, `Illuminate\Support\Facades\Cache`, and `Illuminate\Support\Facades\Notification`.

```php
public function test_reminder_sends_one_digest_for_requests_waiting_more_than_seven_days(): void
{
    Notification::fake();
    Cache::flush();
    $leader = $this->userWithRole('program-leader');
    $project = $this->project();

    $old = $this->approval($project, 'disbursement', 'pending');
    $old->forceFill(['created_at' => now()->subDays(8)])->save();
    $new = $this->approval($project, 'disbursement', 'pending');
    $new->forceFill(['created_at' => now()->subDays(7)])->save();

    $this->artisan('program:approval-reminders')->assertExitCode(0);
    $this->artisan('program:approval-reminders')->assertExitCode(0);

    Notification::assertSentToTimes($leader, ApprovalWaitingDigestNotification::class, 1);
}
```
Add a test that no notification is sent when all requests are 7 days old or newer.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_reminder_sends_one_digest_for_requests_waiting_more_than_seven_days`
Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the command**

- Find pending approvals with `whereDate('created_at', '<=', now()->subDays(8)->toDateString())` and eager-load `project`.
- Find active Program Leaders via `whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))`.
- Return 0 with an info line when there are no overdue approvals or no leaders.
- Use `Cache::add('program-approval-digest:'.now()->toDateString(), true, now()->endOfDay())`; if false, log “already sent” and return 0.
- `Notification::send($leaders, new ApprovalWaitingDigestNotification($approvals, $approvals->count()))`.

- [ ] **Step 4: Implement the digest notification**

`via()` is the same database/mail guard as Task 4.
Compute `$oldestDays = $approvals->min(fn ($approval) => $approval->created_at->startOfDay()->diffInDays(now()->startOfDay()));`.
`toArray()` returns `title` (`N approval requests waiting`), `body` (`Oldest waiting X days`), `url` (`/program-approvals`), and `approval_ids`.
`toMail()` lists up to 10 request titles/projects and the oldest waiting days.

- [ ] **Step 5: Register the schedule**

In `Kernel::schedule()`:
```php
$schedule->command('program:approval-reminders')->dailyAt('08:00');
```

- [ ] **Step 6: Document the Render Cron Job**

Add to `DEPLOYMENT.md`:
```markdown
## Approval reminder scheduler

Create a Render Cron Job from the same repository and backend root directory:

- Command: `php artisan schedule:run`
- Schedule: `* * * * *` (every minute)
- Environment: the same variables as the backend web service
```
Note that Laravel runs the 08:00 command once per day from this minute-level cron.

- [ ] **Step 7: Run the reminder tests**

Run: `php artisan test --filter='NotificationTest|ProgramApprovalsTest'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Notifications/ApprovalWaitingDigestNotification.php backend/app/Console/Commands/SendApprovalWaitingDigest.php backend/app/Console/Kernel.php DEPLOYMENT.md backend/tests/Feature/NotificationTest.php
git commit -m "feat: send daily approval waiting digest"
```

---

### Task 6: Polish the shared Program Leader shell

**Files:**
- Modify: `frontend/src/layouts/ProgramLayout.jsx`
- Create: `frontend/src/components/program/ProgramNotificationMenu.jsx`
- Create: `frontend/src/api/notifications.js`
- Modify: `frontend/src/api/endpoints.js`
- Modify: `frontend/src/styles/global.css`

**Interfaces:**
- Consumes: Task 4 notification API.
- Produces:
  - Sidebar seal image, brand copy, partnership footer.
  - Header avatar centering, disabled “My profile”/“Change password” “Coming soon” entries.
  - Bell opens a notification menu instead of navigating directly to Approvals.
  - All buttons/tabs 44px; brand focus outline; status text+icon.

- [ ] **Step 1: Update the sidebar**

Replace the gold `MSU` span with:
```jsx
<img
  className="program-seal-img"
  src="/images/msu-seal-outline.png"
  alt="MSU seal"
  width="317"
  height="324"
/>
```
Replace the bottom user card with:
```jsx
<div className="program-partner">
  <span>In partnership with</span>
  <img
    src="/images/client-logo-outline.png"
    alt="Partner organization logo"
    width="384"
    height="346"
  />
</div>
```
Keep `LIB Monitoring` at 17px bold and `MSU–Main Campus` at 13px `#EDE4D6`.

- [ ] **Step 2: Add the profile menu entries**

Add two disabled menu items before Sign out:
```jsx
<button type="button" className="program-user-menu-item" role="menuitem" disabled aria-disabled="true" title="My profile is not available yet">
  {ICONS.user}
  <span>My profile</span>
  <em className="program-soon">Coming soon</em>
</button>
<button type="button" className="program-user-menu-item" role="menuitem" disabled aria-disabled="true" title="Change password is not available yet">
  {ICONS.key}
  <span>Change password</span>
  <em className="program-soon">Coming soon</em>
</button>
```
Add `ICONS.user` and `ICONS.key` SVGs. No profile/change-password routes exist; do not invent them.

- [ ] **Step 3: Add the notification menu**

`ProgramNotificationMenu`:
- Props: none; it uses `useAuth` only if needed.
- Fetches `fetchNotifications()` on mount and when opened.
- Renders a bell button with `aria-haspopup="menu"`, `aria-expanded`, and an unread dot.
- Dropdown lists `title`, `body`, relative time; clicking a row calls `markNotificationRead(id)` and navigates to `url` when present.
- Include a “Pending approvals” link to `/program-approvals` so the old bell behavior is preserved.
- Footer button “Mark all as read” calls `markAllNotificationsRead()`.
- Click-outside and Escape close the menu.

Add API wrappers:
```js
export async function fetchNotifications() { ... }
export async function markNotificationRead(id) { ... }
export async function markAllNotificationsRead() { ... }
```
Add endpoint constants `notifications`, `notificationRead(id)`, `notificationsReadAll`.

- [ ] **Step 4: Add the CSS**

In the Program Leader section of `global.css`:
- `.program-shell .program-seal-img{height:54px;width:auto;flex:0 0 auto;display:block}`
- Remove the gold `.program-seal` circle usage; keep the old selector only if another page uses it.
- `.program-shell .program-brand-name{font-size:17px;font-weight:700}`
- `.program-shell .program-brand-sub{font-size:13px;color:#EDE4D6}`
- `.program-partner{margin-top:auto;border-top:1px solid rgba(255,255,255,.2);padding:16px 12px 0;display:flex;flex-direction:column;gap:10px}`
- `.program-partner span{font-size:12px;color:#EDE4D6}`
- `.program-partner img{height:72px;width:auto;max-width:100%;object-fit:contain}`
- `.program-shell .program-avatar{display:grid;place-items:center;line-height:1}`
- `.program-soon{margin-left:auto;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#8A6D12}`
- Set `.program-shell .program-btn`, `.program-shell .program-btn.sm`, `.program-shell .program-row-actions .program-btn`, `.program-shell .program-tab`, `.program-shell .program-icon-btn`, `.program-user-chip`, `.program-user-menu-item` to `min-height:44px`.
- Set disabled buttons to `opacity:.5`.
- End the Program Leader section with:
```css
.program-shell :focus-visible{outline:3px solid rgba(111,29,42,.4);outline-offset:2px}
```
Remove/override the later gold `:focus-visible` and gold select focus rules so the brand outline wins.

- [ ] **Step 5: Build**

Run: `npm run build` in `frontend`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/layouts/ProgramLayout.jsx frontend/src/components/program/ProgramNotificationMenu.jsx frontend/src/api/notifications.js frontend/src/api/endpoints.js frontend/src/styles/global.css
git commit -m "feat: polish Program Leader shell and notifications"
```

---

### Task 7: Polish Overview and make Projects query-aware

**Files:**
- Modify: `frontend/src/pages/dashboards/ProgramLeaderDashboard.jsx`
- Modify: `frontend/src/pages/program/ProgramProjects.jsx`
- Create: `frontend/src/components/program/ProgramDecisionPanel.jsx`
- Create: `frontend/src/lib/projectFilters.js`
- Create: `frontend/src/lib/projectFilters.test.js`
- Modify: `frontend/src/styles/global.css`
- Modify: `backend/app/Services/ProgramDashboardService.php`
- Test: `backend/tests/Feature/ProgramDashboardTest.php`

**Interfaces:**
- Consumes: existing overview payload and Projects page.
- Produces:
  - KPI cards are single `<Link>` elements.
  - “Remaining balance” with caption “Approved budget not yet disbursed”.
  - Receipt compliance in the “Spending against time” header and per-project receipt line.
  - No-funder group labelled “No funder assigned”, linked to `/program-projects?funder=none`, excluded from active-funder count.
  - `parseProjectQuery(search)` / `buildProjectQuery({ atRiskOnly, funder })`.
  - Projects reads `?health=at-risk` and `?funder=none`.
  - `ProgramDecisionPanel` shared inline approve confirmation for Overview and Approvals.

- [ ] **Step 1: Write the failing frontend query test**

`frontend/src/lib/projectFilters.test.js`:
```js
import assert from 'node:assert/strict';
import test from 'node:test';
import { buildProjectQuery, parseProjectQuery } from './projectFilters.js';

test('Overview links drive the Projects filters', () => {
  assert.deepEqual(parseProjectQuery('?health=at-risk'), { atRiskOnly: true, funder: 'all' });
  assert.deepEqual(parseProjectQuery('?funder=none'), { atRiskOnly: false, funder: 'none' });
  assert.equal(buildProjectQuery({ atRiskOnly: true, funder: 'all' }), 'health=at-risk');
  assert.equal(buildProjectQuery({ atRiskOnly: false, funder: 'none' }), 'funder=none');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test src/lib/projectFilters.test.js` in `frontend`
Expected: FAIL because the module does not exist.

- [ ] **Step 3: Implement `projectFilters.js`**

```js
export function parseProjectQuery(search) {
  const params = new URLSearchParams(search);
  const health = params.get('health');
  const funder = params.get('funder');
  return {
    atRiskOnly: health === 'at-risk',
    funder: funder === 'none' ? 'none' : (funder || 'all'),
  };
}

export function buildProjectQuery({ atRiskOnly, funder }) {
  const params = new URLSearchParams();
  if (atRiskOnly) params.set('health', 'at-risk');
  if (funder === 'none') params.set('funder', 'none');
  else if (funder && funder !== 'all') params.set('funder', funder);
  return params.toString();
}
```

- [ ] **Step 4: Make Projects read/write the query**

Use `useSearchParams` or `useLocation` + `useNavigate`. Initialize `atRiskOnly` and `funder` from `parseProjectQuery(location.search)`, sync on `location.search`, and update the query when the At risk button or funder select changes.
Add `<option value="none">No funder assigned</option>`.
Filter:
```js
const matchesFunder = funder === 'all'
  || (funder === 'none' ? !project.funder_id : String(project.funder_id) === String(funder) || project.funder === funder);
```
Remove the developer note `<p className="program-help">…</p>` but keep the disabled `New project · Coming soon` button.

- [ ] **Step 5: Update the backend no-funder label**

Change `ProgramDashboardService::funderLabel()` to return `No funder assigned` when `fundingAgency` is null.
Add a backend test:
```php
public function test_overview_labels_unassigned_funders(): void
{
    $leader = $this->userWithRole('program-leader');
    Project::create([
        'code' => 'PRG-NOFUND-' . uniqid(),
        'title' => 'No funder project',
        'funding_agency_id' => null,
        'total_approved_budget' => 100000,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'ongoing',
    ]);

    $response = $this->actingAs($leader)->getJson('/api/program/overview')->assertOk();
    $group = collect($response->json('funding_sources'))->firstWhere('funder', 'No funder assigned');

    $this->assertNotNull($group);
    $this->assertNull($group['funder_id']);
}
```

- [ ] **Step 6: Update the Overview cards**

Replace each `<section className="program-card pl-kpi …">` with a `<Link>`:
- Portfolio budget → `/program-projects`
- Total disbursed → `/program-projects`
- Project health → `/program-projects?health=at-risk`
- Pending approvals → `/program-approvals`

Add `aria-label` to each link. Add CSS:
```css
.program-shell a.pl-kpi{color:inherit;text-decoration:none}
.program-shell a.pl-kpi:hover{border-color:#D9C79A}
```
Remove the Pending approvals `pl-kpi-sub` “Oldest waiting…” line; keep a short foot such as “N in the dashboard queue”.
Change the wallet hero label to “Remaining balance” and add the caption “Approved budget not yet disbursed”.
Add receipt compliance to the “Spending against time” card head:
```jsx
<span className="pl-receipt-summary">
  Receipts {compliance != null ? formatPct(compliance) : '—'} · {receiptCount}/{expenseCount} expenses
</span>
```
Add a per-project line under the bars:
```jsx
<div className="pl-spend-receipts">
  {project.expenses_count > 0 && project.receipt_compliance_pct != null
    ? `Receipts ${formatPct(project.receipt_compliance_pct)} · ${project.receipts_count}/${project.expenses_count}`
    : 'No expenses'}
</div>
```
In Funding sources, compute `activeFunderCount = fundingSources.filter((source) => source.funder_id != null).length` and use it in the sub-line. For the no-funder row, wrap the row in `<Link to="/program-projects?funder=none">`; keep other rows as divs.

- [ ] **Step 7: Use the shared approve confirmation on Overview**

Create `ProgramDecisionPanel` with props `{ id, note, onNoteChange, onConfirm, onCancel, busy }` and the exact copy:
- label `Add a note for the requester (optional)`
- buttons `Confirm approval` and `Cancel`
- `aria-live="polite"` busy/error area

In `ProgramLeaderDashboard`, replace the direct `handleApprove(approval)` click with `setApproveId(approval.id); setApproveNote('')`. Render the panel under the approval row when `approveId === approval.id`. `onConfirm` calls `handleApprove(approval, approveNote.trim() || null)`, closes the panel, and calls `refreshAll()` so the Overview pending count and sidebar badge update without a full page reload. Keep the existing reject modal because it already requires a reason.

- [ ] **Step 8: Run the frontend query test and build**

Run: `node --test src/lib/projectFilters.test.js` and `npm run build` in `frontend`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/pages/dashboards/ProgramLeaderDashboard.jsx frontend/src/pages/program/ProgramProjects.jsx frontend/src/components/program/ProgramDecisionPanel.jsx frontend/src/lib/projectFilters.js frontend/src/lib/projectFilters.test.js frontend/src/styles/global.css backend/app/Services/ProgramDashboardService.php backend/tests/Feature/ProgramDashboardTest.php
git commit -m "feat: polish Program Leader overview and project filters"
```

---

### Task 8: Polish Approvals details and decisions

**Files:**
- Modify: `frontend/src/pages/program/ProgramApprovals.jsx`
- Create: `frontend/src/components/program/ProgramApprovalDetails.jsx`
- Create: `frontend/src/components/program/ProgramWaitingPill.jsx`
- Use: `frontend/src/components/program/ProgramDecisionPanel.jsx` (created in Task 7)
- Modify: `frontend/src/api/program.js`
- Modify: `frontend/src/api/endpoints.js`
- Modify: `frontend/src/styles/global.css`

**Interfaces:**
- Consumes: Task 2 `wait_level`; Task 3 details/attachment endpoints; existing decision endpoint.
- Produces:
  - `fetchProgramApproval(id)` and `downloadProgramAttachment(id, index)`.
  - `ProgramWaitingPill({ days, level })` with neutral ≤7, amber ⚠ 8–14, red ✕ >14.
  - Inline “View details” panel with exact colors and type-specific sections.
  - Approve inline confirmation and Reject required reason.
  - Recently decided third line with remarks or “No note”.

- [ ] **Step 1: Add API wrappers and waiting pill**

```js
export async function fetchProgramApproval(id) {
  const response = await client.get(ENDPOINTS.programApproval(id));
  return response.data;
}

export async function downloadProgramAttachment(id, index) {
  const response = await client.get(ENDPOINTS.programAttachment(id, index), { responseType: 'blob' });
  return response.data;
}
```
Add `programApproval(id)` and `programAttachment(id, index)` to `ENDPOINTS`.

`ProgramWaitingPill` (import `ProgramPill` from `./ProgramPill`):
```jsx
export default function ProgramWaitingPill({ days = 0, level }) {
  const resolved = level ?? (days > 14 ? 'critical' : days > 7 ? 'warning' : 'normal');
  const tone = resolved === 'critical' ? 'risk' : resolved === 'warning' ? 'watch' : 'neutral';
  const icon = resolved === 'critical' ? '✕' : resolved === 'warning' ? '⚠' : '⏱';
  return <ProgramPill tone={tone} icon={icon} label={`Waiting ${days} day${days === 1 ? '' : 's'}`} />;
}
```

- [ ] **Step 2: Add the details panel**

`ProgramApprovalDetails` receives `approval`, `details`, `loading`, `error`, `onDownload`.
Render:
- Requester name, role, requested date, justification or “No justification provided”.
- Realignment: source/destination line items with Allocated, Disbursed, Balance before, Balance after; share of source allocation; amber pill `Above {threshold_pct}% of the source line item` when `above_threshold`.
- Fund release: tranche name, amount, target date, “N days overdue” when >0, previous tranche liquidated ✓/✕, project receipt compliance.
- Liquidation: amount, expenses with/without receipts, tranche being liquidated.
- Attachments: name, type, size; Download button when `available`; otherwise “File unavailable”; “No attachments” when empty.
- Loading and error states with `aria-live="polite"`.

CSS:
```css
.program-details{background:#FAF7F2;border:1px solid #EDE4D6;border-radius:10px;padding:14px;margin:6px 0 14px}
.program-details-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px}
.program-details-table{width:100%;border-collapse:collapse;margin-top:10px}
.program-details-table th,.program-details-table td{padding:6px 8px;border-bottom:1px solid #EDE4D6;text-align:left;font-size:12px}
@media (max-width:640px){.program-details-grid{grid-template-columns:1fr}}
```

- [ ] **Step 3: Add sorting and row actions**

- State: `sort` (`oldest`/`newest`), `detailsId`, `detailsCache`, `detailsLoading`, `detailsError`, `approveId`, `approveNote`, `rejectId`, `rejectReason`.
- Add an “Order” select with “Oldest first” and “Newest first”; sort by `created_at` ascending/descending.
- Add a “View details” button per row with `aria-expanded` and `aria-controls`.
- On expand, fetch details once and cache by id.
- Opening details, approve, or reject for a row closes the other panels for that row.
- Preserve the existing Pending/Approved/Rejected tabs with counts and the Type, Project, and Funder filters.
- Show `ProgramWaitingPill` in the row title line.
- Maker-checker: keep buttons disabled, set CSS opacity `.5`, and render a warning pill with an icon:
```jsx
<span className="program-pill watch"><span aria-hidden="true">⚠</span> You cannot approve a request you created</span>
```

- [ ] **Step 4: Add the approve confirmation and reject reason**

Approve:
```jsx
<button type="button" className="program-btn primary" onClick={() => { setApproveId(approval.id); setApproveNote(''); setRejectId(null); }}>
  Approve
</button>
```
Render the shared panel when `approveId === approval.id`:
```jsx
<ProgramDecisionPanel
  id={approval.id}
  note={approveNote}
  onNoteChange={setApproveNote}
  onConfirm={() => confirmApprove(approval)}
  onCancel={() => setApproveId(null)}
  busy={busy}
/>
```
`confirmApprove` calls `decideApproval(id, { decision: 'approved', remarks: approveNote.trim() || null })`.
Reject label is exactly “Reason for rejection”; add `required` and keep the client-side trim check plus API validation. Opening Reject clears `approveId`; opening Approve clears `rejectId`.
After either decision: set feedback, close panels, `await refreshAll()`. `refreshAll` reloads the list (tab counts) and calls `refreshPending()` (sidebar badge). Keep the feedback banner `role="status" aria-live="polite"`.

- [ ] **Step 5: Update Recently decided**

Use three lines:
```jsx
<div className="program-row-title">
  <ProgramPill value={approval.status === 'approved' ? 'Approved' : 'Rejected'} />
  <span>{approval.title}</span>
</div>
<div className="program-row-sub">
  {approval.project} · {formatDate(approval.reviewed_at)}
  {approval.reviewed_by ? ` · decided by ${approval.reviewed_by}` : ''}
  {approval.reviewed_by_role ? ` (${approval.reviewed_by_role})` : ''}
</div>
<div className="program-row-note">{approval.review_notes || 'No note'}</div>
```
Add `.program-row-note{font-size:12px;color:#6B5F5F;margin-top:2px}`.

- [ ] **Step 6: Build**

Run: `npm run build` in `frontend`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/program/ProgramApprovals.jsx frontend/src/components/program/ProgramApprovalDetails.jsx frontend/src/components/program/ProgramWaitingPill.jsx frontend/src/api/program.js frontend/src/api/endpoints.js frontend/src/styles/global.css
git commit -m "feat: polish approval details and decisions"
```

---

### Task 9: Verify and report

**Files:** none.

**Interfaces:**
- Consumes: all previous tasks.
- Produces: verified build, test results, responsive evidence, and final report.

- [ ] **Step 1: Run backend tests**

Run: `php artisan test` in `backend`
Expected: all tests pass.

- [ ] **Step 2: Run frontend build and query test**

Run: `npm run build` in `frontend`
Run: `node --test src/lib/projectFilters.test.js` in `frontend`
Expected: both pass.

- [ ] **Step 3: Verify logos are shipped**

Run: `Get-ChildItem frontend/dist/images | Select-Object Name,Length`
Expected: `msu-seal-outline.png` and `client-logo-outline.png` present.

- [ ] **Step 4: Responsive checks**

Run the app and check Overview, Approvals, and one other Program Leader page (Projects) at:
- 1366px wide
- 800px wide
- 390px wide

Confirm:
- No horizontal page scroll at 390px.
- Sidebar opens/closes and the partnership footer is visible.
- KPI cards are single focusable links with the 3px brand outline.
- Approvals details and decision panels wrap within the card.
- Waiting pills show text plus icon.
- Logos load from `/images/` and are not distorted.

- [ ] **Step 5: Final report**

List:
- Changed files.
- Render migration command: `php artisan migrate --force`.
- Render Cron Job: `php artisan schedule:run` every minute.
- New env var: `PROGRAM_REALIGNMENT_WARNING_PCT=10`; mail env vars required for email.
- Missing New project items: create/update API, validation, funding-agency and Project Leader assignment, tranche setup, and audit entry.
- Any limitations found: no request-creation form/API; no documents table (attachments resolved from existing path columns); liquidation expense counts are project-wide unless `payload.expense_ids` is present.
