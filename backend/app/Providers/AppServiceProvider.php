<?php

namespace App\Providers;

use App\Database\MySqlConnection;
use App\Mail\Transport\BrevoTransport;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\ProjectUser;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Database\Connection;
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
        // MySQL connections use a schema grammar that also works on TiDB
        // (one ALTER TABLE per added column; see App\Database\MySqlSchemaGrammar).
        Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new MySqlConnection($connection, $database, $prefix, $config);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // MAIL_MAILER=brevo sends through Brevo's HTTPS API.
        $this->app['mail.manager']->extend('brevo', function (array $config) {
            return new BrevoTransport($config['key'] ?? null, (int) ($config['timeout'] ?? 10));
        });

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
