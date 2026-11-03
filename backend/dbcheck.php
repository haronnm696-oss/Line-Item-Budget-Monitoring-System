<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectTranche;

echo "PROJECTS\n";
foreach (Project::with('fundingAgency')->orderBy('id')->get() as $p) {
    echo json_encode([
        'id' => $p->id,
        'code' => $p->code,
        'title' => $p->title,
        'funder_id' => $p->funding_agency_id,
        'funder' => $p->fundingAgency?->acronym,
        'budget' => $p->total_approved_budget,
        'status' => $p->status,
        'start' => $p->start_date?->toDateString(),
        'end' => $p->end_date?->toDateString(),
        'expense_verified' => (float) Expense::where('project_id', $p->id)->where('status', 'verified')->sum('amount'),
        'expense_all' => (float) Expense::where('project_id', $p->id)->sum('amount'),
        'payroll' => (float) Payroll::where('project_id', $p->id)->whereIn('status', ['approved', 'released'])->sum('net_pay'),
        'expenses' => Expense::where('project_id', $p->id)->count(),
        'pending_approvals' => ApprovalRequest::where('project_id', $p->id)->where('status', 'pending')->count(),
        'tranches' => ProjectTranche::where('project_id', $p->id)->orderBy('sequence')->get(['sequence', 'status', 'amount', 'planned_release_date'])->toArray(),
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
}

echo "APPROVALS\n";
foreach (ApprovalRequest::with('project')->orderBy('id')->get() as $a) {
    echo json_encode([
        'id' => $a->id,
        'project' => $a->project?->code,
        'type' => $a->type,
        'title' => $a->title,
        'amount' => $a->amount,
        'status' => $a->status,
        'tranche_id' => $a->project_tranche_id,
        'created' => (string) $a->created_at,
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;
}

echo "AGENCIES\n";
foreach (FundingAgency::orderBy('id')->get() as $f) {
    echo json_encode($f->toArray(), JSON_UNESCAPED_UNICODE), PHP_EOL;
}

echo "DB CHARSET\n";
foreach (DB::select("SELECT @@character_set_database AS db_charset, @@collation_database AS db_collation") as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
echo "TABLE CHARSETS\n";
foreach (DB::select("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('projects','approval_requests','funding_agencies','lib_categories','expenses','payrolls')") as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
echo "COLUMN CHARSETS\n";
foreach (DB::select("SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('projects','approval_requests','funding_agencies') AND CHARACTER_SET_NAME IS NOT NULL") as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
echo "REALIGNMENT HEX\n";
foreach (ApprovalRequest::where('type', 'budget_realignment')->get() as $a) {
    echo $a->id . ' ' . bin2hex($a->title) . ' ' . $a->title . PHP_EOL;
}
