<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Expense;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Documents attached to an expense. Project scoping (project.access) limits
 * every route to the Program Leader and members of the expense's project.
 * Upload and delete each write exactly one audit entry.
 */
class DocumentController extends Controller
{
    private const UPLOADERS = ['research-assistant', 'finance-admin'];

    public function index(Expense $expense)
    {
        return response()->json([
            'documents' => $expense->documents()->with('uploader')->orderBy('id')->get()->map(fn (Document $d) => $this->row($d))->values(),
        ]);
    }

    public function store(Request $request, Expense $expense)
    {
        $actor = $request->user();
        abort_unless(
            in_array($actor->roleOn($expense->project), self::UPLOADERS, true),
            403,
            'Only a Research Assistant or Finance/Admin staff of this project can attach documents.'
        );
        abort_unless(
            in_array($expense->workflow_status, ['draft', 'returned'], true),
            422,
            'Documents can be attached only while the expense is a draft or returned.'
        );

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:' . implode(',', config('documents.mimes')), 'max:' . config('documents.max_kb')],
            'document_type' => ['required', Rule::in(Document::TYPES)],
        ], [
            'file.mimes' => 'Upload a PDF, JPG or PNG file.',
            'file.max' => 'The file must not be larger than 10 MB.',
        ]);

        $file = $data['file'];
        $disk = config('documents.disk');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs('documents/expenses/' . $expense->id, Str::uuid() . '.' . $extension, ['disk' => $disk]);

        $document = AuditLogger::transaction(function () use ($expense, $actor, $data, $file, $disk, $path) {
            $document = $expense->documents()->create([
                'uploaded_by' => $actor->id,
                'document_type' => $data['document_type'],
                'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'file_path' => $path,
                'disk' => $disk,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'uploaded_at' => now(),
            ]);

            AuditLogger::write('created', $document, [], $this->auditValues($document, $expense), $actor);

            return $document;
        });

        return response()->json(['message' => 'Document uploaded.', 'document' => $this->row($document->load('uploader'))], 201);
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

        $expense = $document->documentable;
        abort_if($expense instanceof Expense && $expense->workflow_status === 'verified', 422, 'Documents of a verified expense cannot be deleted.');

        AuditLogger::transaction(function () use ($document, $expense, $actor) {
            $values = $this->auditValues($document, $expense);
            $document->delete();
            AuditLogger::write('deleted', $document, $values, [], $actor);
        });

        // Remove the file once the row is gone; a missing file is fine.
        Storage::disk($document->disk)->delete($document->file_path);

        return response()->json(['message' => 'Document deleted.']);
    }

    private function auditValues(Document $document, $expense): array
    {
        return [
            'project_id' => $expense?->project_id,
            'expense_id' => $document->documentable_id,
            'document_type' => $document->document_type,
            'file_name' => $document->file_name,
        ];
    }

    private function row(Document $document): array
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
