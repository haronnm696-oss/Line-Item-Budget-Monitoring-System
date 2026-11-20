<?php

namespace App\Http\Controllers;

use App\Support\MoneyInput;
use App\Rules\DateInRange;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Services\ProjectSetupService;
use App\Support\LineItemComputation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Program Leader only (routes under the role.program-leader middleware):
 * create a project and set up its details, Project Leader, Line-Item Budget
 * and planned releases (tranches in the data model). Rules and audit entries: ProjectSetupService.
 */
class ProgramProjectSetupController extends Controller
{
    private const MONEY = MoneyInput::RULES;

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
            'start_date' => ['bail', 'required', new DateInRange(), 'date'],
            'end_date' => ['bail', 'required', new DateInRange(), 'date', 'after:start_date'],
            'project_leader_id' => ['required', 'integer'],
        ] + $this->moaRules(), $this->messages());

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
            'start_date' => ['bail', 'sometimes', 'required', new DateInRange(), 'date'],
            'end_date' => ['bail', 'sometimes', 'required', new DateInRange(), 'date', 'after:' . ($request->input('start_date') ?? $project->start_date?->toDateString())],
            'project_leader_id' => ['sometimes', 'required', 'integer'],
        ] + $this->moaRules() + $this->formBPrintRules(), $this->messages());

        $this->setup->updateProject($project, $data, $request->user());

        return response()->json(['message' => 'Project details saved.']);
    }

    /** Optional fields from the Memorandum of Agreement. */
    private function moaRules(): array
    {
        return [
            'moa_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'moa_date' => ['bail', 'sometimes', 'nullable', new DateInRange(), 'date_format:Y-m-d'],
            'rd_committee_approved_date' => ['bail', 'sometimes', 'nullable', new DateInRange(), 'date_format:Y-m-d'],
            'fund_source' => ['sometimes', 'nullable', 'string', 'max:255'],
            // "Grant amount from the funder (per MOA)"; blank = not entered.
            'funding_agency_amount' => array_merge(['sometimes', 'nullable'], self::MONEY),
        ];
    }

    /** Optional printed Form B fields: Monitoring Agency and signatory names and positions. */
    private function formBPrintRules(): array
    {
        return collect(Project::formBPrintFields())
            ->mapWithKeys(fn ($field) => [$field => ['sometimes', 'nullable', 'string', 'max:255']])
            ->all();
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

        return response()->json(['message' => 'Release added.', 'tranche' => $this->tranche($tranche)], 201);
    }

    /** POST /api/program/projects/{project}/tranches/split: Release 1 (70%) and Release 2 (30%). */
    public function splitTranches(Request $request, Project $project)
    {
        $tranches = $this->setup->splitTranches($project, $request->user());

        return response()->json([
            'message' => 'Release 1 (70%) and Release 2 (30%) added.',
            'tranches' => array_map(fn (ProjectTranche $t) => $this->tranche($t), $tranches),
        ], 201);
    }

    /** PATCH /api/program/tranches/{projectTranche} */
    public function updateTranche(Request $request, ProjectTranche $projectTranche)
    {
        $data = $request->validate($this->trancheRules(false), $this->messages());
        $tranche = $this->setup->updateTranche($projectTranche, $data, $request->user());

        return response()->json(['message' => 'Release saved.', 'tranche' => $this->tranche($tranche)]);
    }

    /** DELETE /api/program/tranches/{projectTranche} */
    public function destroyTranche(Request $request, ProjectTranche $projectTranche)
    {
        $this->setup->removeTranche($projectTranche, $request->user());

        return response()->json(['message' => 'Release removed.']);
    }

    // ---------------------------------------------------------------- helpers

    private function lineItemRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        $optionalMoney = array_merge(['sometimes', 'nullable'], self::MONEY);

        return [
            'category_type' => array_merge($required, [Rule::in(['PS', 'MOOE'])]),
            'cost_type' => ['sometimes', 'nullable', Rule::in(LibCategory::COST_TYPES)],
            'expense_group' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expense_subgroup' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_item_name' => array_merge($required, ['string', 'max:255']),
            // Optional computation: quantity x rate [per month|quarter] x periods.
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000', 'required_with:rate'],
            'unit_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rate' => array_merge($optionalMoney, ['required_with:quantity']),
            'rate_period' => ['sometimes', 'nullable', Rule::in(LineItemComputation::PERIODS)],
            'periods' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            // The two sources; allocated = their sum. allocated_amount alone
            // (older clients) sets the funder share.
            'implementing_agency_amount' => $optionalMoney,
            'funder_amount' => $optionalMoney,
            'allocated_amount' => $optionalMoney,
        ];
    }

    private function trancheRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => array_merge($required, ['gt:0'], self::MONEY),
            'planned_release_date' => array_merge(['bail'], $required, [new DateInRange(), 'date']),
        ];
    }

    private function messages(): array
    {
        return [
            'code.unique' => 'Another project already uses this code.',
            'end_date.after' => 'The end date must be after the start date.',
            'moa_date.date_format' => 'Enter the MOA date as a date.',
            'rd_committee_approved_date.date_format' => 'Enter the approval date as a date.',
            '*.regex' => 'Use at most two decimals.',
            'allocated_amount.min' => 'The allocated amount cannot be negative.',
            'funder_amount.min' => 'The amount cannot be negative.',
            'implementing_agency_amount.min' => 'The amount cannot be negative.',
            'quantity.required_with' => 'Enter the quantity for the computation.',
            'rate.required_with' => 'Enter the rate for the computation.',
            'total_approved_budget.gt' => 'The total budget must be more than zero.',
            'amount.gt' => 'The release amount must be more than zero.',
        ];
    }

    private function lineItem(LibCategory $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->category_type,
            'cost_type' => $item->cost_type,
            'group' => $item->expense_group,
            'subgroup' => $item->expense_subgroup,
            'name' => $item->line_item_name,
            'computation' => $item->computationText(),
            'implementing_agency_amount' => round((float) $item->implementing_agency_amount, 2),
            'funder_amount' => round((float) $item->funder_amount, 2),
            'allocated' => round((float) $item->allocated_amount, 2),
        ];
    }

    private function tranche(ProjectTranche $tranche): array
    {
        return [
            'id' => $tranche->id, 'sequence' => (int) $tranche->sequence, 'name' => $tranche->releaseLabel(),
            'amount' => round((float) $tranche->amount, 2), 'planned_release_date' => $tranche->planned_release_date?->toDateString(),
        ];
    }
}
