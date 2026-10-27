<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;

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
Route::post('/login', [AuthController::class, 'login'])->middleware('web');
Route::post('/register', [AuthController::class, 'register'])->middleware('web');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('web', 'throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('web');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('web');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

// Dashboard analytics & quick actions.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/program-leader', [DashboardController::class, 'programLeader']);
    Route::get('/projects/{project}/tranches', [DashboardController::class, 'tranches']);
    Route::get('/projects/{project}/burn-rate', [DashboardController::class, 'burnRate']);
    Route::get('/projects/{project}/compliance', [DashboardController::class, 'compliance']);

    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::patch('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::patch('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);

    Route::get('/projects/{project}/reports/{type}', [ReportController::class, 'export']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
