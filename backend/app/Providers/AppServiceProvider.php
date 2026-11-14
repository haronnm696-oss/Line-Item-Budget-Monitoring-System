<?php

namespace App\Providers;

use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\ProjectUser;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Audit log for add / edit / delete (FR: complete audit log of all
        // critical actions). Approvals and account decisions write their own,
        // more specific entries.
        foreach ([Expense::class, Payroll::class, LibCategory::class, ProjectTranche::class, Project::class, User::class, ProjectUser::class] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
