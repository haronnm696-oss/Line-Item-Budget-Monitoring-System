<?php

namespace Tests\Feature\Concerns;

use App\Models\Document;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\ProjectPersonnel;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * A project with one person per project role (RA, PL, FIN, a second FIN and
 * a researcher), plus a second project nobody here is assigned to.
 */
trait BuildsExpenseWorkflow
{
    protected Project $project;
    protected Project $otherProject;
    protected LibCategory $lineItem;
    protected User $assistant;
    protected User $projectLeader;
    protected User $finance;
    protected User $finance2;
    protected User $researcher;
    protected User $programLeader;
    protected User $outsider;

    protected function buildWorkflowFixtures(): void
    {
        AuditLogger::muted(function () {
            $this->project = $this->makeProject('WF-001', 'Workflow project');
            $this->otherProject = $this->makeProject('WF-002', 'Other project');
            $this->lineItem = LibCategory::create([
                'project_id' => $this->project->id,
                'category_type' => 'MOOE',
                'line_item_name' => 'Supplies',
                'allocated_amount' => 500000,
            ]);

            $this->assistant = $this->member('research-assistant');
            $this->projectLeader = $this->member('project-leader');
            $this->finance = $this->member('finance-admin');
            $this->finance2 = $this->member('finance-admin');
            $this->researcher = $this->member('researcher');
            $this->programLeader = $this->makePerson('program-leader');

            // Finance/Admin on the other project only.
            $this->outsider = $this->makePerson('finance-admin');
            $this->assignTo($this->outsider, $this->otherProject, 'finance-admin');
        });
    }

    protected function makePerson(string $slug): User
    {
        return User::create([
            'name' => ucwords(str_replace('-', ' ', $slug)) . ' ' . substr(uniqid(), -5),
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private ?User $freeLeaderUser = null;

    /**
     * A Project Leader account that leads no project yet ($projectLeader
     * already leads the active WF-001, and a Project Leader leads one active
     * project at a time). The same account on every call in a test.
     */
    protected function freeLeader(): User
    {
        return $this->freeLeaderUser ??= AuditLogger::muted(fn () => $this->makePerson('project-leader'));
    }

    protected function member(string $slug): User
    {
        $user = $this->makePerson($slug);
        $this->assignTo($user, $this->project, $slug);

        return $user;
    }

    protected function assignTo(User $user, Project $project, string $slug): void
    {
        ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $slug)->value('id'),
            'assigned_at' => now(),
        ]);
    }

    /**
     * Payroll fixture: the person's personnel record (honorarium) on the
     * project, covering the project period. null removes the record.
     */
    protected function setHonorarium(User $user, ?float $monthly, ?Project $project = null): void
    {
        $project ??= $this->project;
        AuditLogger::muted(function () use ($user, $monthly, $project) {
            ProjectPersonnel::where('project_id', $project->id)->where('user_id', $user->id)->delete();
            if ($monthly !== null) {
                ProjectPersonnel::create([
                    'project_id' => $project->id, 'user_id' => $user->id, 'position' => 'Project Staff',
                    'special_order_no' => 'SO No. 0001 s. 2026',
                    'effective_from' => $project->start_date, 'effective_to' => $project->end_date,
                    'monthly_honorarium' => $monthly,
                ]);
            }
        });
    }

    protected function makeProject(string $code, string $title): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => $code, 'title' => $title, 'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'ongoing',
        ]);
    }

    protected function expensePayload(array $overrides = []): array
    {
        return array_merge([
            'lib_category_id' => $this->lineItem->id,
            'description' => 'Laboratory reagents',
            'payee' => 'Iligan Lab Supply',
            'reference_no' => 'OR-1001',
            'amount' => 2500.50,
            'expense_date' => '2026-11-10',
        ], $overrides);
    }

    /** An expense in a given workflow state, built without the API (no audit, no notifications). */
    protected function expenseIn(string $workflowStatus, ?User $recorder = null, bool $withReceipt = true, array $extra = []): Expense
    {
        return AuditLogger::muted(function () use ($workflowStatus, $recorder, $withReceipt, $extra) {
            $expense = Expense::create(array_merge([
                'project_id' => $this->project->id,
                'lib_category_id' => $this->lineItem->id,
                'recorded_by' => ($recorder ?? $this->assistant)->id,
                'description' => 'Field transport',
                'reference_no' => 'OR-' . random_int(2000, 9999),
                'amount' => 1200,
                'expense_date' => '2026-11-05',
                'workflow_status' => $workflowStatus,
                'status' => Expense::STATUS_FOR_WORKFLOW[$workflowStatus],
            ], $extra));

            if ($withReceipt) {
                $this->receiptFor($expense, $recorder ?? $this->assistant);
            }

            return $expense;
        });
    }

    protected function receiptFor(Expense $expense, User $uploader, string $path = null): Document
    {
        return $expense->documents()->create([
            'uploaded_by' => $uploader->id,
            'document_type' => Document::TYPE_RECEIPT,
            'file_name' => 'receipt.pdf',
            'file_path' => $path ?? 'documents/expenses/' . $expense->id . '/receipt.pdf',
            'disk' => 'local',
            'size' => 1024,
            'mime' => 'application/pdf',
            'uploaded_at' => now(),
        ]);
    }

    /** A payroll file on the batch (needed before it can be submitted), built without the API. */
    protected function payrollFileFor(PayrollBatch $batch, User $uploader): Document
    {
        return $batch->documents()->create([
            'uploaded_by' => $uploader->id,
            'document_type' => 'payroll_file',
            'file_name' => 'payroll.pdf',
            'file_path' => 'documents/payroll-batches/' . $batch->id . '/payroll.pdf',
            'disk' => 'local',
            'size' => 2048,
            'mime' => 'application/pdf',
            'uploaded_at' => now(),
        ]);
    }

    /**
     * A fake upload with its own bytes. UploadedFile::fake()->create() only
     * reports a size (its contents are empty), so two of them are identical
     * and the second is refused as a duplicate on the same record.
     */
    protected function uniqueUpload(string $name, int $kilobytes = 20, ?string $mime = null): File
    {
        return UploadedFile::fake()->createWithContent($name, $name . ':' . uniqid('', true))
            ->size($kilobytes)
            ->mimeType($mime ?? 'application/pdf');
    }
}
