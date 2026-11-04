<?php

namespace TobiasHesselink\SiteAlerts;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;
use TobiasHesselink\SiteAlerts\Console\RemindFailedJobs;
use TobiasHesselink\SiteAlerts\Console\SendTestAlert;
use TobiasHesselink\SiteAlerts\Listeners\ReportException;
use TobiasHesselink\SiteAlerts\Listeners\ReportFailedJob;
use TobiasHesselink\SiteAlerts\Listeners\ReportScheduledTasks;

class SiteAlertsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/site-alerts.php', 'site-alerts');

        $this->app->singleton(Discord::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/site-alerts.php' => config_path('site-alerts.php')], 'site-alerts-config');

        Event::listen(JobFailed::class, ReportFailedJob::class);
        Event::subscribe(ReportScheduledTasks::class);

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    rescue(fn () => $this->app->make(ReportException::class)($e), report: false);
                });
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SendTestAlert::class, RemindFailedJobs::class]);

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));
        }
    }

    private function schedule(Schedule $schedule): void
    {
        if ($url = (string) config('site-alerts.heartbeat.url')) {
            $schedule->call(fn () => Heartbeat::start($url, Heartbeat::queues()))
                ->everyFiveMinutes()
                ->name('site-alerts:heartbeat');
        }

        match (strtolower((string) config('site-alerts.failed_jobs.reminder'))) {
            'daily' => $schedule->command(RemindFailedJobs::class)->dailyAt('09:00'),
            'weekly' => $schedule->command(RemindFailedJobs::class)->weeklyOn(1, '09:00'),
            default => null,
        };

        $days = (int) config('site-alerts.failed_jobs.retention_days');

        if ($days > 0 && $this->app->make('queue.failer') instanceof PrunableFailedJobProvider) {
            $schedule->command('queue:prune-failed', ['--hours' => $days * 24])->dailyAt('04:30');
        }
    }
}
