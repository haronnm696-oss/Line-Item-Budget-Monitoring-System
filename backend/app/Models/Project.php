<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'title', 'description', 'funding_agency_id',
        'total_approved_budget', 'implementing_agency_counterpart',
        'funding_agency_amount', 'start_date', 'end_date', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_approved_budget' => 'decimal:2',
        'implementing_agency_counterpart' => 'decimal:2',
        'funding_agency_amount' => 'decimal:2',
    ];

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

    public function tranches()
    {
        return $this->hasMany(ProjectTranche::class)->orderBy('sequence');
    }

    public function approvalRequests()
    {
        return $this->hasMany(ApprovalRequest::class);
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

    /** Total spent = verified expenses + released/approved payroll, optionally by PS/MOOE. */
    public function totalSpent(?string $categoryType = null): float
    {
        $expenseSpent = $this->expenses()
            ->where('status', 'verified')
            ->whereHas('libCategory', function ($q) use ($categoryType) {
                if ($categoryType) {
                    $q->where('category_type', $categoryType);
                }
            })
            ->sum('amount');

        $payrollSpent = $this->payrolls()
            ->whereIn('status', ['approved', 'released'])
            ->whereHas('libCategory', function ($q) use ($categoryType) {
                if ($categoryType) {
                    $q->where('category_type', $categoryType);
                }
            })
            ->sum('net_pay');

        return (float) ($expenseSpent + $payrollSpent);
    }

    public function totalRemaining(?string $categoryType = null): float
    {
        return $this->totalAllocated($categoryType) - $this->totalSpent($categoryType);
    }
}
