<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Contracts\HasDocuments;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PayrollBatch;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Documents attached to a record (expense, monthly payroll batch, purchase
 * request, budget request). Project scoping (project.access) limits every route to
 * the Program Leader and members of the record's project; each record type
 * sets who may upload, when, and which types (HasDocuments::documentRules).
 * Upload and delete each write exactly one audit entry.
 */
class DocumentController extends Controller
{
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

        $document = AuditLogger::transaction(fn () => self::attach($record, $data['file'], $data['document_type'], $actor));

        return response()->json(['message' => 'Document uploaded.', 'document' => self::row($document->load('uploader'))], 201);
    }

    public function download(Document $document)
    {
        $storage = Storage::disk($document->disk);
        abort_unless($storage->exists($document->file_path), 404, 'File unavailable.');

        return $storage->download($document->file_path, $document->file_name);
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

        AuditLogger::transaction(function () use ($document, $record, $actor) {
            $values = self::auditValues($document, $record);
            $document->delete();
            AuditLogger::write('deleted', $document, $values, [], $actor);
        });

        // Remove the file once the row is gone; a missing file is fine.
        Storage::disk($document->disk)->delete($document->file_path);

        return response()->json(['message' => 'Document deleted.']);
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
            "{$prefix}.max" => 'The file must not be larger than 10 MB.',
        ];
    }

    public static function mayUpload(HasDocuments $record, User $actor): bool
    {
        $rules = $record->documentRules();
        if (array_key_exists('uploader_ids', $rules)) {
            return in_array((int) $actor->id, array_map('intval', $rules['uploader_ids']), true);
        }

        $project = $record->getAttribute('project_id') ? Project::find($record->getAttribute('project_id')) : null;

        return $project !== null && in_array($actor->roleOn($project), $rules['uploader_roles'], true);
    }

    /**
     * Store the file and its row with one "created" audit entry. Call inside
     * an AuditLogger::transaction. If the transaction fails, the stored file
     * is removed.
     */
    public static function attach(HasDocuments $record, UploadedFile $file, string $type, User $actor): Document
    {
        /** @var Model&HasDocuments $record */
        $disk = config('documents.disk');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $folder = 'documents/' . $record->documentRules()['folder'] . '/' . $record->getKey();
        $path = $file->storeAs($folder, Str::uuid() . '.' . $extension, ['disk' => $disk]);

        try {
            $document = $record->documents()->create([
                'uploaded_by' => $actor->id,
                'document_type' => $type,
                'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'file_path' => $path,
                'disk' => $disk,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'uploaded_at' => now(),
            ]);

            AuditLogger::write('created', $document, [], self::auditValues($document, $record), $actor);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return $document;
    }

    private static function auditValues(Document $document, $record): array
    {
        return [
            'project_id' => $record?->project_id,
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
        ];
    }
}
