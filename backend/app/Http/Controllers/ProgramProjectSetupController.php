<?php

namespace App\Http\Controllers;

use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Services\ProjectSetupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Program Leader only (routes under the role.program-leader middleware):
 * create a project and set up its details, Project Leader, Line-Item Budget
 * and planned tranches. Rules and audit entries: ProjectSetupService.
 */
class ProgramProjectSetupController extends Controller
{
    private const MONEY = ['numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];

    public function __construct(private ProjectSetupService $setup)
    {
    }

    /** GET /api/program/project-options: funders and Project Leader accounts. */
    public function options()
    {
        return response()->json($this->setup->options());
    }

    /** POST /api/program/projects */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('projects', 'code')],
            'funding_agency_id' => ['required', 'integer', 'exists:funding_agencies,id'],
            'funder_reference' => ['nullable', 'string', 'max:100'],
            'total_approved_budget' => array_merge(['required', 'gt:0'], self::MONEY),
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'project_leader_id' => ['required', 'integer'],
        ], $this->messages());

        $project = $this->setup->createProject($data, $request->user());

        return response()->json(['message' => 'Project created.', 'project' => ['id' => $project->id, 'code' => $project->code, 'title' => $project->title]], 201);
    }

    /** PATCH /api/program/projects/{project} */
    public function update(Request $request, Project $project)
    {
        $data = $request->validate([
            'funding_agency_id' => ['sometimes', 'required', 'integer', 'exists:funding_agencies,id'],
            'funder_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'total_approved_budget' => array_merge(['sometimes', 'required', 'gt:0'], self::MONEY),
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:' . ($request->input('start_date') ?? $project->start_date?->toDateString())],
            'project_leader_id' => ['sometimes', 'required', 'integer'],
        ], $this->messages());

        $this->setup->updateProject($project, $data, $request->user());

        return response()->json(['message' => 'Project details saved.']);
    }

    /** POST /api/program/projects/{project}/line-items */
    public function storeLineItem(Request $request, Project $project)
    {
        $data = $request->validate($this->lineItemRules(true), $this->messages());
        $item = $this->setup->addLineItem($project, $data, $request->user());

        return response()->json(['message' => 'Line item added.', 'line_item' => $this->lineItem($item)], 201);
    }

    /** PATCH /api/program/line-items/{libCategory} */
    public function updateLineItem(Request $request, LibCategory $libCategory)
    {
        $data = $request->validate($this->lineItemRules(false), $this->messages());
        $item = $this->setup->updateLineItem($libCategory, $data, $request->user());

        return response()->json(['message' => 'Line item saved.', 'line_item' => $this->lineItem($item)]);
    }

    /** DELETE /api/program/line-items/{libCategory} */
    public function destroyLineItem(Request $request, LibCategory $libCategory)
    {
        $this->setup->removeLineItem($libCategory, $request->user());

        return response()->json(['message' => 'Line item removed.']);
    }

    /** POST /api/program/projects/{project}/tranches */
    public function storeTranche(Request $request, Project $project)
    {
        $data = $request->validate($this->trancheRules(true), $this->messages());
        $tranche = $this->setup->addTranche($project, $data, $request->user());

        return response()->json(['message' => 'Tranche added.', 'tranche' => $this->tranche($tranche)], 201);
    }

    /** PATCH /api/program/tranches/{projectTranche} */
    public function updateTranche(Request $request, ProjectTranche $projectTranche)
    {
        $data = $request->validate($this->trancheRules(false), $this->messages());
        $tranche = $this->setup->updateTranche($projectTranche, $data, $request->user());

        return response()->json(['message' => 'Tranche saved.', 'tranche' => $this->tranche($tranche)]);
    }

    /** DELETE /api/program/tranches/{projectTranche} */
    public function destroyTranche(Request $request, ProjectTranche $projectTranche)
    {
        $this->setup->removeTranche($projectTranche, $request->user());

        return response()->json(['message' => 'Tranche removed.']);
    }

    // ---------------------------------------------------------------- helpers

    private function lineItemRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'category_type' => array_merge($required, [Rule::in(['PS', 'MOOE'])]),
            'line_item_name' => array_merge($required, ['string', 'max:255']),
            'allocated_amount' => array_merge($required, self::MONEY),
        ];
    }

    private function trancheRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => array_merge($required, ['gt:0'], self::MONEY),
            'planned_release_date' => array_merge($required, ['date']),
        ];
    }

    private function messages(): array
    {
        return [
            'code.unique' => 'Another project already uses this code.',
            'end_date.after_or_equal' => 'The end date must be on or after the start date.',
            '*.regex' => 'Use at most two decimals.',
            'allocated_amount.min' => 'The allocated amount cannot be negative.',
            'total_approved_budget.gt' => 'The total budget must be more than zero.',
            'amount.gt' => 'The tranche amount must be more than zero.',
        ];
    }

    private function lineItem(LibCategory $item): array
    {
        return ['id' => $item->id, 'type' => $item->category_type, 'name' => $item->line_item_name, 'allocated' => round((float) $item->allocated_amount, 2)];
    }

    private function tranche(ProjectTranche $tranche): array
    {
        return [
            'id' => $tranche->id, 'sequence' => (int) $tranche->sequence, 'name' => $tranche->name,
            'amount' => round((float) $tranche->amount, 2), 'planned_release_date' => $tranche->planned_release_date?->toDateString(),
        ];
    }
}
