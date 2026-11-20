<?php

namespace App\Http\Controllers;

use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectPersonnel;
use App\Services\PersonnelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Personnel section of a project (PersonnelService has the rules):
 *   GET    /api/projects/{project}/personnel                  edit / view / limited by role, Researchers 403
 *   POST   /api/program/projects/{project}/personnel          Program Leader
 *   PATCH  /api/program/personnel/{personnel}                 Program Leader
 *   DELETE /api/program/personnel/{personnel}                 Program Leader
 *   POST   /api/program/personnel/{personnel}/special-order   Program Leader (file upload)
 *   DELETE /api/program/personnel/{personnel}/special-order   Program Leader
 */
class PersonnelController extends Controller
{
    public function __construct(private PersonnelService $personnel)
    {
    }

    public function index(Request $request, Project $project)
    {
        return response()->json($this->personnel->listing($project, $request->user()));
    }

    public function store(Request $request, Project $project)
    {
        $data = $request->validate(['user_id' => ['required', 'integer']] + $this->rules($project), $this->messages());
        $record = $this->personnel->create($project, $data, $request->user());

        return response()->json(['message' => 'Personnel added.', 'personnel' => $this->personnel->row($record->fresh(['user', 'lineItem', 'documents']))], 201);
    }

    public function update(Request $request, ProjectPersonnel $personnel)
    {
        $data = $request->validate($this->rules($personnel->project), $this->messages());
        $record = $this->personnel->update($personnel, $data, $request->user());

        return response()->json(['message' => 'Personnel saved.', 'personnel' => $this->personnel->row($record->fresh(['user', 'lineItem', 'documents']))]);
    }

    public function destroy(Request $request, ProjectPersonnel $personnel)
    {
        $this->personnel->remove($personnel, $request->user());

        return response()->json(['message' => 'Personnel removed.']);
    }

    public function uploadSpecialOrder(Request $request, ProjectPersonnel $personnel)
    {
        $data = $request->validate(['file' => DocumentController::fileRules()], DocumentController::fileMessages());
        $document = $this->personnel->uploadSpecialOrder($personnel, $data['file'], $request->user());

        return response()->json(['message' => 'Special Order uploaded.', 'document' => DocumentController::row($document->load('uploader'))], 201);
    }

    public function removeSpecialOrder(Request $request, ProjectPersonnel $personnel)
    {
        $this->personnel->removeSpecialOrder($personnel, $request->user());

        return response()->json(['message' => 'Special Order removed.']);
    }

    /** Effective dates must fall inside the project period. */
    private function rules(Project $project): array
    {
        $start = $project->start_date?->toDateString();
        $end = $project->end_date?->toDateString();
        $within = array_filter([
            $start ? "after_or_equal:{$start}" : null,
            $end ? "before_or_equal:{$end}" : null,
        ]);

        return [
            'position' => ['required', 'string', 'max:255'],
            'lib_category_id' => ['required', 'integer', Rule::exists((new LibCategory())->getTable(), 'id')
                ->where('project_id', $project->id)->where('category_type', 'PS')],
            'special_order_no' => ['required', 'string', 'max:100'],
            'effective_from' => array_merge(['required', 'date_format:Y-m-d'], $within),
            'effective_to' => array_merge(['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'], $within),
            'monthly_honorarium' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:34', 'regex:/^[0-9][0-9 -]*[0-9]$/'],
        ];
    }

    private function messages(): array
    {
        return [
            'position.required' => 'Enter the position as written in Form B.',
            'lib_category_id.required' => 'Choose the PS line item.',
            'lib_category_id.exists' => 'Choose a Personal Services (PS) line item of this project.',
            'special_order_no.required' => 'Enter the Special Order number.',
            'effective_from.after_or_equal' => 'The start date must be within the project period.',
            'effective_from.before_or_equal' => 'The start date must be within the project period.',
            'effective_to.after_or_equal' => 'The end date must be on or after the start date, within the project period.',
            'effective_to.before_or_equal' => 'The end date must be within the project period.',
            'monthly_honorarium.min' => 'The monthly honorarium cannot be negative.',
            'monthly_honorarium.numeric' => 'Enter the monthly honorarium as a number.',
            'monthly_honorarium.regex' => 'Use at most two decimals for the monthly honorarium.',
            'bank_account.regex' => 'Enter the bank account number using digits (spaces and dashes are allowed).',
        ];
    }
}
