<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

/* Health check used by Render: proves PHP + database are reachable. */
Route::get('/up', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo()->query('SELECT 1');
        return response('ok', 200, ['Content-Type' => 'text/plain']);
    } catch (\Throwable $e) {
        return response('db error: ' . $e->getMessage(), 500, ['Content-Type' => 'text/plain']);
    }
});
