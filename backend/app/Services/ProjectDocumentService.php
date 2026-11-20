<?php

namespace App\Services;

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Project documents: the MOA and Forms A (proposal), B (line-item budget)
 * and C (work plan), plus any other file, attached to the project itself.
 * Same storage, preview, 5 MB limit and duplicate check as receipts
 * (DocumentController::attach). Access per role:
 *   manage    Program Leader: upload, remove, preview, download
 *   download  Project Leader, Finance/Admin, Researcher and Research
 *             Assistant of the project: preview and download
 * Upload and removal each write one audit entry.
 */
class ProjectDocumentService
{
    public const MANAGE = 'manage';
    public const DOWNLOAD = 'download';

    public const LABELS = [
        'moa' => 'Memorandum of Agreement (MOA)',
        'form_a' => 'Form A (proposal)',
        'form_b' => 'Form B (line-item budget)',
        'form_c' => 'Form C (work plan)',
        'other' => 'Other',
    ];

    public function access(Project $project, User $user): ?string
    {
        if ($user->isProgramLeader()) {
            return self::MANAGE;
        }
        $roles = $user->rolesOn($project);

        return array_intersect($roles, ['project-leader', 'finance-admin', 'researcher', 'research-assistant']) !== []
            ? self::DOWNLOAD
            : null;
    }

    public function listing(Project $project, User $user): array
    {
        $access = $this->access($project, $user);
        abort_if($access === null, 403, 'You are not assigned to this project.');

        return [
            'access' => $access,
            'types' => collect(self::LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'documents' => $project->documents()->with('uploader')->orderBy('id')->get()
                ->map(fn (Document $document) => DocumentController::row($document) + [
                    'type_label' => self::LABELS[$document->document_type] ?? $document->document_type,
                ])->values(),
        ];
    }

    public function upload(Project $project, UploadedFile $file, string $type, User $actor): Document
    {
        return AuditLogger::transaction(fn () => DocumentController::attach($project, $file, $type, $actor));
    }

    public function remove(Document $document, User $actor): void
    {
        $project = $document->documentable;
        abort_unless($project instanceof Project, 404, 'This is not a project document.');

        AuditLogger::transaction(fn () => DocumentController::detach($document, $project, $actor));
        DocumentController::deleteFiles([$document]);
    }
}
