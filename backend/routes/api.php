<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\BalancingController;
use App\Http\Controllers\BudgetRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayRateController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProgramApprovalController;
use App\Http\Controllers\ProgramAuditController;
use App\Http\Controllers\ProgramDashboardController;
use App\Http\Controllers\ProgramProjectController;
use App\Http\Controllers\ProgramUserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectLeaderController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResearchAssistantController;
use App\Http\Controllers\UserApprovalController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Gmail + password auth (Sanctum SPA cookie flow).
// Email is globally unique; each user has one primary role that
// decides which dashboard they land on after login.
Route::post('/login', [AuthController::class, 'login'])->middleware('web', 'throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('web', 'throttle:5,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('web', 'throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('web');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('web');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum', 'active.account');

// Dashboard analytics & quick actions.
// active.account: a deactivated user loses access on the next request.
// project.access: routes naming a project (or a project's record) are open
// only to the Program Leader and users assigned to that project.
Route::middleware(['auth:sanctum', 'active.account', 'project.access'])->group(function () {
    Route::get('/dashboard/program-leader', [DashboardController::class, 'programLeader']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::get('/projects/{project}/tranches', [DashboardController::class, 'tranches']);
    Route::get('/projects/{project}/burn-rate', [DashboardController::class, 'burnRate']);
    Route::get('/projects/{project}/compliance', [DashboardController::class, 'compliance']);

    Route::get('/audit', [AuditTrailController::class, 'index']);

    // Read-only. Decisions are Program Leader-only: POST /program/approvals/{approval}/decision.
    Route::get('/approvals', [ApprovalController::class, 'index']);

    // Expense workflow (ExpenseWorkflowService): draft -> submitted ->
    // endorsed ("Approved by Project Leader") -> verified, or returned.
    Route::get('/projects/{project}/expenses', [ExpenseController::class, 'index']);
    Route::post('/projects/{project}/expenses', [ExpenseController::class, 'store']);
    Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
    Route::post('/expenses/{expense}/submit', [ExpenseController::class, 'submit']);
    Route::post('/expenses/{expense}/endorse', [ExpenseController::class, 'endorse']);
    Route::post('/expenses/{expense}/return', [ExpenseController::class, 'returnExpense']);
    Route::post('/expenses/{expense}/verify', [ExpenseController::class, 'verify']);
    Route::post('/expenses/{expense}/reject', [ExpenseController::class, 'reject']);
    // Earlier PATCH form of verify / reject, same rules.
    Route::patch('/expenses/{expense}/verify', [ExpenseController::class, 'verify']);
    Route::patch('/expenses/{expense}/reject', [ExpenseController::class, 'reject']);

    // Payroll (PayrollService): create / compute a month's rows; submit,
    // approve / return and verify / reject act on the month's batch.
    // Not part of LIB spent.
    Route::get('/projects/{project}/payroll', [PayrollController::class, 'index']);
    Route::post('/projects/{project}/payroll', [PayrollController::class, 'store']);
    Route::post('/projects/{project}/payroll/compute', [PayrollController::class, 'compute']);
    Route::patch('/payroll/{payroll}', [PayrollController::class, 'update']);
    Route::post('/payroll-batches/{payrollBatch}/submit', [PayrollController::class, 'submit']);
    Route::post('/payroll-batches/{payrollBatch}/endorse', [PayrollController::class, 'endorse']);
    Route::post('/payroll-batches/{payrollBatch}/return', [PayrollController::class, 'returnBatch']);
    Route::post('/payroll-batches/{payrollBatch}/verify', [PayrollController::class, 'verify']);
    Route::post('/payroll-batches/{payrollBatch}/reject', [PayrollController::class, 'reject']);
    Route::get('/projects/{project}/pay-rates', [PayRateController::class, 'index']);
    Route::patch('/projects/{project}/pay-rates/{user}', [PayRateController::class, 'update']);

    // Purchase requests (PurchaseRequestService): draft -> submitted ->
    // approved by Project Leader, or returned; an approved request ends as
    // fulfilled (an expense is linked) or cancelled. No Finance step.
    Route::get('/projects/{project}/purchase-requests', [PurchaseRequestController::class, 'index']);
    Route::post('/projects/{project}/purchase-requests', [PurchaseRequestController::class, 'store']);
    Route::patch('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update']);
    Route::delete('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'destroy']);
    Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit']);
    Route::post('/purchase-requests/{purchaseRequest}/endorse', [PurchaseRequestController::class, 'endorse']);
    Route::post('/purchase-requests/{purchaseRequest}/return', [PurchaseRequestController::class, 'returnRequest']);
    Route::post('/purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel']);

    // Budget requests from the Project Leader to the Program Leader.
    Route::get('/projects/{project}/budget-requests', [BudgetRequestController::class, 'index']);
    Route::post('/projects/{project}/budget-requests', [BudgetRequestController::class, 'store']);

    // Balancing query per line item.
    Route::get('/projects/{project}/balancing', [BalancingController::class, 'show']);

    // Project Leader pages (read-only; decisions use the workflow routes above).
    Route::get('/projects/{project}/leader-overview', [ProjectLeaderController::class, 'overview']);
    Route::get('/projects/{project}/leader-badges', [ProjectLeaderController::class, 'badges']);
    Route::post('/projects/{project}/budget-requests/seen', [ProjectLeaderController::class, 'requestsSeen']);
    Route::get('/projects/{project}/review-queue', [ProjectLeaderController::class, 'queue']);
    Route::get('/projects/{project}/review-queue/{type}/{id}', [ProjectLeaderController::class, 'show'])->where('id', '[0-9]+');
    // Verified (spent) expenses, 25 per page; any member of the project.
    Route::get('/projects/{project}/transactions', [ProjectLeaderController::class, 'transactions']);

    // Research Assistant pages (read-only; changes use the workflow routes above).
    Route::get('/projects/{project}/assistant-overview', [ResearchAssistantController::class, 'overview']);
    Route::get('/projects/{project}/assistant-badges', [ResearchAssistantController::class, 'badges']);
    Route::get('/projects/{project}/my-submissions', [ResearchAssistantController::class, 'submissions']);
    Route::get('/projects/{project}/my-submissions/{type}/{id}', [ResearchAssistantController::class, 'show'])->where('id', '[0-9]+');
    // Finance/Admin pages (read-only; verify / reject use the workflow routes above).
    Route::get('/projects/{project}/finance-overview', [FinanceController::class, 'overview']);
    Route::get('/projects/{project}/finance-badges', [FinanceController::class, 'badges']);
    Route::get('/projects/{project}/verification-queue', [FinanceController::class, 'queue']);
    Route::get('/projects/{project}/verification-queue/{type}/{id}', [FinanceController::class, 'show'])->where('id', '[0-9]+');
    Route::get('/projects/{project}/payroll-batches', [FinanceController::class, 'payrollBatches']);
    // Notify RA: ask for the missing receipt of a verified expense (Finance/Admin).
    Route::post('/expenses/{expense}/request-receipt', [FinanceController::class, 'requestReceipt']);
    // Read-only budget summary for any project member (Researcher, Finance/Admin).
    Route::get('/projects/{project}/budget-summary', [FinanceController::class, 'summary']);

    // Every document of the project's records (project members).
    Route::get('/projects/{project}/documents', [ResearchAssistantController::class, 'documents']);

    // Documents attached to a record (project members only).
    Route::get('/expenses/{expense}/documents', [DocumentController::class, 'indexForExpense']);
    Route::post('/expenses/{expense}/documents', [DocumentController::class, 'storeForExpense']);
    Route::get('/payroll-batches/{payrollBatch}/documents', [DocumentController::class, 'indexForPayrollBatch']);
    Route::post('/payroll-batches/{payrollBatch}/documents', [DocumentController::class, 'storeForPayrollBatch']);
    Route::get('/purchase-requests/{purchaseRequest}/documents', [DocumentController::class, 'indexForPurchaseRequest']);
    Route::post('/purchase-requests/{purchaseRequest}/documents', [DocumentController::class, 'storeForPurchaseRequest']);
    Route::get('/approvals/{approval}/documents', [DocumentController::class, 'indexForApproval']);
    Route::post('/approvals/{approval}/documents', [DocumentController::class, 'storeForApproval']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

    // In-app notifications of the signed-in user.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // Self-registration approval queue (Program Leader / Finance-Admin).
    Route::get('/users/pending', [UserApprovalController::class, 'index']);
    Route::patch('/users/{user}/approve', [UserApprovalController::class, 'approve']);

    Route::get('/projects/{project}/reports/{type}', [ReportController::class, 'export']);

    // Program-wide dashboard: all projects visible to a Program Leader.
    Route::middleware('role.program-leader')->group(function () {
        Route::get('/program/overview', [ProgramDashboardController::class, 'overview']);
        Route::get('/program/projects', [ProgramProjectController::class, 'index']);
        Route::get('/program/projects/export', [ProgramProjectController::class, 'export']);
        Route::get('/program/projects/{project}', [ProgramProjectController::class, 'show']);
        Route::get('/program/approvals', [ProgramApprovalController::class, 'index']);
        // Read-only: entries are never edited or deleted through the API.
        Route::get('/program/audit', [ProgramAuditController::class, 'index']);
        Route::post('/program/approvals/{approval}/decision', [ProgramDashboardController::class, 'decision']);

        // Account review and per-project role management.
        Route::get('/program/registrations', [ProgramUserController::class, 'registrations']);
        Route::post('/program/registrations/{user}/decision', [ProgramUserController::class, 'decideRegistration']);
        Route::get('/program/users', [ProgramUserController::class, 'index']);
        Route::post('/program/users', [ProgramUserController::class, 'store']);
        Route::patch('/program/users/{user}', [ProgramUserController::class, 'updateDetails']);
        Route::patch('/program/users/{user}/status', [ProgramUserController::class, 'updateStatus']);
        Route::patch('/program/users/{user}/role', [ProgramUserController::class, 'updateRole']);
        Route::post('/program/assignments', [ProgramUserController::class, 'assign']);
        Route::patch('/program/assignments/{user}/{project}', [ProgramUserController::class, 'updateAssignment']);
        Route::delete('/program/assignments/{user}/{project}', [ProgramUserController::class, 'removeAssignment']);
    });
});

Route::middleware(['auth:sanctum', 'active.account'])->get('/user', function (Request $request) {
    return $request->user();
});
