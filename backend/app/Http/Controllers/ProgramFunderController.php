<?php

namespace App\Http\Controllers;

use App\Models\FundingAgency;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Program Leader only: the funders a project can have. Each has a full name
 * (e.g. "Ministry of Science and Technology - BARMM") and a short name (the
 * acronym column, e.g. "MOST") that labels the funder column on screens.
 * No funder is built into the system.
 */
class ProgramFunderController extends Controller
{
    /** GET /api/program/funders */
    public function index()
    {
        return response()->json([
            'funders' => FundingAgency::query()->withCount('projects')->orderBy('name')->get()
                ->map(fn (FundingAgency $agency) => $this->row($agency))->values()->all(),
        ]);
    }

    /** POST /api/program/funders */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $agency = AuditLogger::transaction(function () use ($data, $request) {
            $agency = FundingAgency::create($data);
            AuditLogger::write('created', $agency, [], $agency->only(['name', 'acronym']), $request->user());

            return $agency;
        });

        return response()->json(['message' => 'Funder added.', 'funder' => $this->row($agency->loadCount('projects'))], 201);
    }

    /** PATCH /api/program/funders/{fundingAgency} */
    public function update(Request $request, FundingAgency $fundingAgency)
    {
        $data = $this->validated($request, $fundingAgency);
        AuditLogger::transaction(function () use ($data, $request, $fundingAgency) {
            $old = $fundingAgency->only(['name', 'acronym']);
            $fundingAgency->update($data);
            AuditLogger::write('updated', $fundingAgency, $old, $fundingAgency->only(['name', 'acronym']), $request->user());
        });

        return response()->json(['message' => 'Funder saved.', 'funder' => $this->row($fundingAgency->fresh()->loadCount('projects'))]);
    }

    private function validated(Request $request, ?FundingAgency $current = null): array
    {
        $data = $request->validate([
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'acronym' => [
                $current ? 'sometimes' : 'required', 'string', 'max:50', 'not_regex:/^\s*$/',
                Rule::unique('funding_agencies', 'acronym')->ignore($current?->id),
            ],
        ], [
            'name.required' => 'Enter the funder\'s full name.',
            'acronym.required' => 'Enter the short name shown on screens.',
            'acronym.unique' => 'Another funder already uses this short name.',
        ]);

        return array_map(fn ($value) => trim((string) $value), $data);
    }

    private function row(FundingAgency $agency): array
    {
        return [
            'id' => $agency->id,
            'name' => $agency->name,
            'short_name' => $agency->acronym,
            'acronym' => $agency->acronym,
            'project_count' => (int) ($agency->projects_count ?? 0),
        ];
    }
}
