<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
