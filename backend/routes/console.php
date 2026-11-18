<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Read-only: users whose college / department is not in config/academic_units.php.
// Their values are kept and shown as they are; nothing is changed.
Artisan::command('users:academic-units-report', function () {
    $legacy = \App\Support\AcademicUnits::legacyUsers();
    $total = \App\Models\User::count();
    $this->info("{$legacy->count()} of {$total} users have a college / department not in the list.");
    $legacy->groupBy(fn ($user) => ($user->college ?? '(none)') . ' / ' . ($user->department ?? '(none)'))
        ->each(fn ($users, $pair) => $this->line(sprintf('  %3d  %s', $users->count(), $pair)));
})->purpose('Count users whose college / department is not in the MSU-Main list (no changes)');
