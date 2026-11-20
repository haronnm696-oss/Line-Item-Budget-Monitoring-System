<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use App\Services\FormBReportService;
use App\Services\ProjectFinancialService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model implements HasDocuments
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'title', 'description', 'funding_agency_id', 'funder_reference',
        'total_approved_budget', 'implementing_agency_counterpart',
        'funding_agency_amount', 'start_date', 'end_date', 'status',
        'monitoring_agency',
        'reviewed_by_name', 'reviewed_by_position',
        'funds_certified_by_name', 'funds_certified_by_position',
        'recommending_approval_name', 'recommending_approval_position',
        'approved_by_name', 'approved_by_position',
        'moa_number', 'moa_date', 'rd_committee_approved_date', 'fund_source',
    ];

    /** Optional fields from the Memorandum of Agreement (Program Leader). */
    public const MOA_FIELDS = ['moa_number', 'moa_date', 'rd_committee_approved_date', 'fund_source'];

    /** Project documents (Program Leader uploads; see documentRules()). */
    public const DOCUMENT_TYPES = ['moa', 'form_a', 'form_b', 'form_c', 'other'];

    /**
     * R&D Form B signatories: column prefix => printed label. Each has
     * optional <prefix>_name and <prefix>_position columns.
     */
    public const FORM_B_SIGNATORIES = [
        'reviewed_by' => 'Reviewed by:',
        'funds_certified_by' => 'Certified Funds Available:',
        'recommending_approval' => 'Recommending Approval:',
        'approved_by' => 'Approved:',
    ];

    /** The optional printed Form B fields (Monitoring Agency and signatories). */
    public static function formBPrintFields(): array
    {
        $fields = ['monitoring_agency'];
        foreach (array_keys(self::FORM_B_SIGNATORIES) as $prefix) {
            $fields[] = "{$prefix}_name";
            $fields[] = "{$prefix}_position";
        }

        return $fields;
    }

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'moa_date' => 'date',
        'rd_committee_approved_date' => 'date',
        'total_approved_budget' => 'decimal:2',
        'implementing_agency_counterpart' => 'decimal:2',
        'funding_agency_amount' => 'decimal:2',
    ];

    /**
     * The MOA fields and the period in words ("Twelve (12) months", the same
     * wording as Form B), as shown under the project title.
     */
    public function agreement(): array
    {
        $text = fn ($value) => ($value = trim((string) $value)) === '' ? null : $value;

        return [
            'moa_number' => $text($this->moa_number),
            'moa_date' => $this->moa_date?->toDateString(),
            'rd_committee_approved_date' => $this->rd_committee_approved_date?->toDateString(),
            'fund_source' => $text($this->fund_source),
            'duration' => app(FormBReportService::class)->duration($this->start_date, $this->end_date) ?: null,
        ];
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Project documents (MOA, Forms A, B, C, other). Uploaded and removed by
     * the Program Leader through /api/program routes only, so no project role
     * uploads here.
     */
    public function documentRules(): array
    {
        return [
            'folder' => 'projects',
            'types' => self::DOCUMENT_TYPES,
            'uploader_ids' => [],
            'uploader_message' => 'Only the Program Leader can upload project documents.',
            'open' => true,
            'closed_message' => '',
            'locked' => false,
            'locked_message' => '',
        ];
    }

    public function fundingAgency()
    {
        return $this->belongsTo(FundingAgency::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->using(ProjectUser::class)
            ->withPivot('role_id', 'assigned_at')
            ->withTimestamps();
    }

    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function libCategories()
    {
        return $this->hasMany(LibCategory::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function purchaseRequests()
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function payrollBatches()
    {
        return $this->hasMany(PayrollBatch::class);
    }

    public function tranches()
    {
        return $this->hasMany(ProjectTranche::class)->orderBy('sequence');
    }

    public function approvalRequests()
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function liquidations()
    {
        return $this->hasMany(Liquidation::class);
    }

    public function reports()
    {
        return $this->hasMany(ProjectReport::class);
    }

    /** The team appointed by Special Order, paid honoraria through payroll (PersonnelService). */
    public function personnel()
    {
        return $this->hasMany(ProjectPersonnel::class);
    }

    /** Users holding a specific role slug on this project (e.g. all Project Leaders). */
    public function usersWithRole(string $roleSlug)
    {
        return $this->users()->whereHas('projectUsers', function ($q) use ($roleSlug) {
            $q->where('project_id', $this->id)
              ->whereHas('role', fn ($r) => $r->where('slug', $roleSlug));
        });
    }

    /** Total allocated across all LIB line items, optionally filtered by PS/MOOE. */
    public function totalAllocated(?string $categoryType = null): float
    {
        return (float) $this->libCategories()
            ->when($categoryType, fn ($q) => $q->where('category_type', $categoryType))
            ->sum('allocated_amount');
    }

    /**
     * Total spent = verified expenses + verified payroll (the same rule as
     * ProjectFinancialService), optionally by PS/MOOE.
     */
    public function totalSpent(?string $categoryType = null): float
    {
        $byType = function ($q) use ($categoryType) {
            if ($categoryType) {
                $q->where('category_type', $categoryType);
            }
        };

        return round((float) $this->expenses()
            ->tap(fn ($query) => ProjectFinancialService::onlySpent($query))
            ->whereHas('libCategory', $byType)
            ->sum('amount')
            + (float) $this->payrolls()
                ->tap(fn ($query) => ProjectFinancialService::onlySpentPayroll($query))
                ->whereHas('libCategory', $byType)
                ->sum('gross_pay'), 2);
    }

    public function totalRemaining(?string $categoryType = null): float
    {
        return $this->totalAllocated($categoryType) - $this->totalSpent($categoryType);
    }
}
