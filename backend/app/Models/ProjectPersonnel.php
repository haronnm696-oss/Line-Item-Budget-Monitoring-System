<?php

namespace App\Models;

use App\Models\Contracts\HasDocuments;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One person of the project team appointed by Special Order, paid a monthly
 * PS honorarium through payroll (the only source of pay for payroll).
 * Rules, access and audit entries: PersonnelService.
 *
 * The bank account number is stored encrypted and is hidden from
 * serialization; screens get maskedBankAccount() ("****9170") only.
 */
class ProjectPersonnel extends Model implements HasDocuments
{
    public const DOCUMENT_TYPE = 'special_order';

    protected $table = 'project_personnel';

    protected $fillable = [
        'project_id', 'user_id', 'position', 'lib_category_id', 'special_order_no',
        'effective_from', 'effective_to', 'monthly_honorarium', 'bank_account', 'created_by',
    ];

    protected $hidden = ['bank_account'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'monthly_honorarium' => 'decimal:2',
        'bank_account' => 'encrypted',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LibCategory::class, 'lib_category_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** The uploaded Special Order, if any. */
    public function specialOrder(): ?Document
    {
        return $this->documents()->where('document_type', self::DOCUMENT_TYPE)->latest('id')->first();
    }

    /**
     * Uploads go through PersonnelController (Program Leader only), never the
     * generic document routes: no project role may upload here.
     */
    public function documentRules(): array
    {
        return [
            'folder' => 'personnel',
            'types' => [self::DOCUMENT_TYPE],
            'uploader_ids' => [],
            'uploader_message' => 'Only the Program Leader can upload a Special Order.',
            'open' => true,
            'closed_message' => '',
            'locked' => false,
            'locked_message' => '',
        ];
    }

    /** "****9170", or null when no account is stored. */
    public function maskedBankAccount(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->bank_account);

        return $digits === '' ? null : '****' . substr($digits, -4);
    }

    /** True when the effective period overlaps the given calendar month. */
    public function coversMonth(int $year, int $month): bool
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        return ($this->effective_from === null || $this->effective_from->lte($end))
            && ($this->effective_to === null || $this->effective_to->gte($start));
    }
}
