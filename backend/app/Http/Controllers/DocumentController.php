<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Contracts\HasDocuments;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\ProjectPersonnel;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStorage;
use App\Services\ExpenseWorkflowService;
use App\Services\PersonnelService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Documents attached to a record (expense, monthly payroll batch, purchase
 * request, budget request). Project scoping (project.access) limits every route to
 * the Program Leader and members of the record's project; each record type
 * sets who may upload, when, and which types (HasDocuments::documentRules).
 * Upload and delete each write exactly one audit entry.
 */
class DocumentController extends Controller
{
    public const LATE_RECEIPT = 'Late receipt on a verified expense.';

    // One typed pair per record so route-model binding resolves the record.
    public function indexForExpense(Expense $expense) { return $this->index($expense); }
    public function storeForExpense(Request $request, Expense $expense) { return $this->store($request, $expense); }
    public function indexForPayrollBatch(PayrollBatch $payrollBatch) { return $this->index($payrollBatch); }
    public function storeForPayrollBatch(Request $request, PayrollBatch $payrollBatch) { return $this->store($request, $payrollBatch); }
    public function indexForPurchaseRequest(PurchaseRequest $purchaseRequest) { return $this->index($purchaseRequest); }
    public function storeForPurchaseRequest(Request $request, PurchaseRequest $purchaseRequest) { return $this->store($request, $purchaseRequest); }
    public function indexForApproval(ApprovalRequest $approval) { return $this->index($approval); }
    public function storeForApproval(Request $request, ApprovalRequest $approval) { return $this->store($request, $approval); }

    private function index(HasDocuments $record)
    {
        return response()->json([
            'documents' => $record->documents()->with('uploader')->orderBy('id')->get()->map(fn (Document $d) => self::row($d))->values(),
        ]);
    }

    private function store(Request $request, HasDocuments $record)
    {
        $actor = $request->user();
        $rules = $record->documentRules();
        abort_unless(self::mayUpload($record, $actor), 403, $rules['uploader_message']);
        abort_unless($rules['open'], 422, $rules['closed_message']);

        $data = $request->validate([
            'file' => self::fileRules(),
            'document_type' => ['required', Rule::in($rules['types'])],
        ], self::fileMessages());

        // A missing receipt added to a verified expense: the same single audit
        // entry, marked with a reason, and Finance/Admin are told.
        $late = $record instanceof Expense && $record->awaitsLateReceipt();
        $reason = $late ? self::LATE_RECEIPT : null;
        $document = AuditLogger::transaction(fn () => self::attach($record, $data['file'], $data['document_type'], $actor, $reason));
        if ($late) {
            app(ExpenseWorkflowService::class)->notifyLateReceipt($record, $actor);
        }

        return response()->json(['message' => 'Document uploaded.', 'document' => self::row($document->load('uploader'))], 201);
    }

    /**
     * GET /api/documents/{id}/download[?inline=1] — streams the bytes from
     * the document's storage backend. Inline only for images and PDFs
     * (previews), otherwise as an attachment. Access: project.access.
     */
    public function download(Request $request, Document $document, DocumentStorage $storage)
    {
        // A Special Order (personnel): Program Leader, Project Leader and
        // Finance/Admin only; not Research Assistants or Researchers.
        if ($document->documentable instanceof ProjectPersonnel) {
            $access = app(PersonnelService::class)->access($document->documentable->project, $request->user());
            abort_unless(in_array($access, [PersonnelService::EDIT, PersonnelService::VIEW], true), 403, 'You cannot open this Special Order.');
        }

        $previewable = in_array($document->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $response = $storage->response($document, $request->boolean('inline') && $previewable);

        if ($response === null) {
            // Remember it so lists show "File not available" (the row is kept).
            if ($document->file_missing_at === null) {
                $document->forceFill(['file_missing_at' => now()])->saveQuietly();
            }
            abort(404, 'File not available.');
        }

        return $response;
    }

    public function destroy(Request $request, Document $document)
    {
        $actor = $request->user();
        abort_unless((int) $document->uploaded_by === (int) $actor->id, 403, 'Only the person who uploaded a document can delete it.');

        $record = $document->documentable;
        if ($record instanceof HasDocuments) {
            $rules = $record->documentRules();
            abort_if($rules['locked'], 422, $rules['locked_message']);
        }

        AuditLogger::transaction(fn () => self::detach($document, $record, $actor));
        self::deleteFiles([$document]);

        return response()->json(['message' => 'Document deleted.']);
    }

    /**
     * Delete a document's row (and database bytes) with its audit entry.
     * Call inside a transaction, then deleteFiles() once it is committed.
     */
    public static function detach(Document $document, $record, User $actor): void
    {
        $values = self::auditValues($document, $record);
        if ($document->disk === DocumentStorage::DATABASE) {
            app(DocumentStorage::class)->delete($document);
        }
        $document->delete();
        AuditLogger::write('deleted', $document, $values, [], $actor);
    }

    /** After commit: remove detached documents' files on a disk (a missing file is fine). */
    public static function deleteFiles(iterable $documents): void
    {
        $storage = app(DocumentStorage::class);
        foreach ($documents as $document) {
            if ($document->disk !== DocumentStorage::DATABASE) {
                $storage->delete($document);
            }
        }
    }

    // ------------------------------------------------------------ shared helpers

    public static function fileRules(): array
    {
        return ['required', 'file', 'mimes:' . implode(',', config('documents.mimes')), 'max:' . config('documents.max_kb')];
    }

    public static function fileMessages(string $prefix = 'file'): array
    {
        return [
            "{$prefix}.mimes" => 'Upload a PDF, JPG or PNG file.',
            "{$prefix}.max" => 'The file is too large. The limit is 5 MB per file.',
            "{$prefix}.uploaded" => 'The file could not be uploaded. Files must be 5 MB or smaller.',
        ];
    }

    public static function mayUpload(HasDocuments $record, User $actor): bool
    {
        $rules = $record->documentRules();
        if (array_key_exists('uploader_ids', $rules)) {
            return in_array((int) $actor->id, array_map('intval', $rules['uploader_ids']), true);
        }

        $project = $record->getAttribute('project_id') ? Project::find($record->getAttribute('project_id')) : null;
        if ($project === null) {
            return false;
        }
        $roles = $actor->rolesOn($project);

        if (array_intersect($roles, $rules['uploader_roles']) !== []) {
            return true;
        }

        // Roles that may attach only to a record they made (e.g. a Project
        // Leader's own expense).
        if (array_intersect($roles, $rules['recorder_roles'] ?? []) !== []) {
            return (int) ($rules['recorder_id'] ?? 0) === (int) $actor->id;
        }

        return false;
    }

    /**
     * Store the file and its row with one "created" audit entry. Call inside
     * an AuditLogger::transaction. Refuses an exact copy (same SHA-256) of a
     * file already attached to the same record. With a disk backend, the
     * stored file is removed if the transaction fails; database bytes roll
     * back with it.
     */
    public static function attach(HasDocuments $record, UploadedFile $file, string $type, User $actor, ?string $reason = null): Document
    {
        /** @var Model&HasDocuments $record */
        $storage = app(DocumentStorage::class);
        $contents = $file->get();
        $sha256 = hash('sha256', $contents);
        self::refuseDuplicate($record, $sha256, $storage);

        $disk = $storage->defaultDisk();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $folder = 'documents/' . $record->documentRules()['folder'] . '/' . $record->getKey();

        $document = null;
        try {
            $document = $record->documents()->create([
                'uploaded_by' => $actor->id,
                'document_type' => $type,
                'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'file_path' => $folder . '/' . Str::uuid() . '.' . $extension,
                'disk' => $disk,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'uploaded_at' => now(),
            ]);
            $storage->put($document, $contents);

            AuditLogger::write('created', $document, [], self::auditValues($document, $record), $actor, $reason);
        } catch (\Throwable $exception) {
            if ($document && $disk !== DocumentStorage::DATABASE) {
                $storage->delete($document);
            }
            throw $exception;
        }

        return $document;
    }

    /** 422 when the same bytes are already attached to this record. */
    private static function refuseDuplicate(HasDocuments $record, string $sha256, DocumentStorage $storage): void
    {
        $existing = $record->documents()->get();
        $inDatabase = $existing->where('disk', DocumentStorage::DATABASE)->pluck('id');
        $duplicate = $inDatabase->isNotEmpty()
            && DB::table('document_contents')->whereIn('document_id', $inDatabase)->where('sha256', $sha256)->exists();

        if (! $duplicate) {
            $duplicate = $existing->where('disk', '!=', DocumentStorage::DATABASE)
                ->contains(fn (Document $document) => $storage->sha256($document) === $sha256);
        }

        if ($duplicate) {
            throw ValidationException::withMessages(['file' => ['This file is already attached to this record.']]);
        }
    }

    private static function auditValues(Document $document, $record): array
    {
        return [
            'project_id' => $record instanceof Model ? AuditLogger::projectIdOf($record) : null,
            'record' => class_basename($document->documentable_type),
            'record_id' => $document->documentable_id,
            'document_type' => $document->document_type,
            'file_name' => $document->file_name,
        ];
    }

    public static function row(Document $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'file_name' => $document->file_name,
            'size' => $document->size,
            'mime' => $document->mime,
            'uploaded_at' => $document->uploaded_at?->toIso8601String(),
            'uploaded_by' => $document->uploader ? ['id' => $document->uploader->id, 'name' => $document->uploader->name] : null,
            'download_url' => '/api/documents/' . $document->id . '/download',
            // False once the file was found missing ("File not available"); the bytes are never in JSON.
            'available' => $document->file_missing_at === null,
        ];
    }
}
