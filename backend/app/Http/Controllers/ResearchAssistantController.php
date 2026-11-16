<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Services\ResearchAssistantService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only data for the Research Assistant pages. Every change uses the
 * existing workflow endpoints (expenses, purchase requests, payroll,
 * documents).
 */
class ResearchAssistantController extends Controller
{
    public function __construct(private ResearchAssistantService $assistant)
    {
    }

    /** GET /api/projects/{project}/assistant-overview */
    public function overview(Request $request, Project $project)
    {
        $this->assistant->requireAssistant($project, $request->user());

        return response()->json($this->assistant->overview($project, $request->user()));
    }

    /** GET /api/projects/{project}/assistant-badges -> { my_submissions } (= Returned to you) */
    public function badges(Request $request, Project $project)
    {
        $this->assistant->requireAssistant($project, $request->user());

        return response()->json($this->assistant->badges($project, $request->user()));
    }

    /** GET /api/projects/{project}/my-submissions?tab=drafts|waiting|returned|approved|completed|all&type= */
    public function submissions(Request $request, Project $project)
    {
        $this->assistant->requireAssistant($project, $request->user());
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(array_merge(ResearchAssistantService::TABS, ['all']))],
            'type' => ['nullable', Rule::in(ResearchAssistantService::TYPES)],
        ]);

        return response()->json($this->assistant->submissions($project, $request->user(), $data['tab'] ?? 'waiting', $data['type'] ?? null));
    }

    /** GET /api/projects/{project}/my-submissions/{type}/{id} — one record, any stage. */
    public function show(Request $request, Project $project, string $type, int $id)
    {
        $this->assistant->requireAssistant($project, $request->user());
        abort_unless(in_array($type, ResearchAssistantService::TYPES, true), 404);

        return response()->json(['item' => $this->assistant->show($project, $request->user(), $type, $id)]);
    }

    /** GET /api/projects/{project}/documents?document_type=&record=expense|payroll|purchase_request (project members) */
    public function documents(Request $request, Project $project)
    {
        $data = $request->validate([
            'document_type' => ['nullable', Rule::in(Document::TYPES)],
            'record' => ['nullable', Rule::in(ResearchAssistantService::TYPES)],
        ]);

        return response()->json($this->assistant->documents($project, $data));
    }
}
