<?php

namespace Uzapoint\Auditable;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Uzapoint\Auditable\Console\RetryFailedAuditEvents;
use Uzapoint\Auditable\Services\AuditPublisher;

class AuditServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__ . '/../config/auditable.php';

    private const string MIGRATION_PATH = __DIR__ . '/../database/migrations/2026_07_15_000001_create_failed_audit_events_table.php';

    public function register(): void
    {
        $this->mergeConfigFrom(
            self::CONFIG_PATH,
            'auditable'
        );

        $this->app->singleton(AuditPublisher::class, function ($app) {
            return new AuditPublisher();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => config_path('auditable.php'),
            ], ['auditable', 'auditable-config']);

            $this->publishesMigrations([
                self::MIGRATION_PATH => database_path('migrations/2026_07_15_000001_create_failed_audit_events_table.php'),
            ], ['auditable', 'auditable-migrations']);

            $this->commands([RetryFailedAuditEvents::class]);
        }

        // Replay the fallback table on the service's own scheduler. A service
        // can turn this off and schedule audit:retry-failed itself.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (filter_var(config('auditable.fallback.schedule_retries', true), FILTER_VALIDATE_BOOL)) {
                $schedule->command('audit:retry-failed')->everyFiveMinutes()->withoutOverlapping();
            }
        });
    }
}
