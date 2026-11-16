<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A file attached to a record (the paper's ERD "Documents" entity). */
class Document extends Model
{
    public const TYPE_RECEIPT = 'receipt';

    public const TYPES = ['receipt', 'invoice', 'quotation', 'payroll_file', 'other'];

    protected $fillable = [
        'documentable_type', 'documentable_id', 'uploaded_by', 'document_type',
        'file_name', 'file_path', 'disk', 'size', 'mime', 'uploaded_at', 'file_missing_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'uploaded_at' => 'datetime',
        'file_missing_at' => 'datetime',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The project the attached record belongs to (for access checks and the
     * audit log), also when that record was soft-deleted.
     */
    public function projectId(): ?int
    {
        $class = $this->documentable_type;
        if (! $class || ! class_exists($class)) {
            return null;
        }

        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        $projectId = $query->whereKey($this->documentable_id)->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }
}
