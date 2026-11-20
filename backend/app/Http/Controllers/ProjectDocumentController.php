<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Services\ProjectDocumentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project documents (MOA, Forms A/B/C, other). Listing: any member of the
 * project (project.access). Upload and removal: Program Leader routes only
 * (role.program-leader). Rules: ProjectDocumentService.
 */
class ProjectDocumentController extends Controller
{
    public function __construct(private ProjectDocumentService $documents)
    {
    }

    /** GET /api/projects/{project}/project-documents */
    public function index(Request $request, Project $project)
    {
        return response()->json($this->documents->listing($project, $request->user()));
    }

    /** POST /api/program/projects/{project}/documents (file, document_type) */
    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'file' => DocumentController::fileRules(),
            'document_type' => ['required', Rule::in(Project::DOCUMENT_TYPES)],
        ], DocumentController::fileMessages() + [
            'document_type.required' => 'Choose the document type.',
            'document_type.in' => 'Choose the document type.',
        ]);

        $document = $this->documents->upload($project, $data['file'], $data['document_type'], $request->user());

        return response()->json(['message' => 'Document uploaded.', 'document' => DocumentController::row($document->load('uploader'))], 201);
    }

    /** DELETE /api/program/project-documents/{document} */
    public function destroy(Request $request, Document $document)
    {
        $this->documents->remove($document, $request->user());

        return response()->json(['message' => 'Document removed.']);
    }
}
