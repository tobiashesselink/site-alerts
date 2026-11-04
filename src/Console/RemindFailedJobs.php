<?php

namespace TobiasHesselink\SiteAlerts\Console;

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use TobiasHesselink\SiteAlerts\Discord;

class RemindFailedJobs extends Command
{
    protected $signature = 'site-alerts:failed-jobs';

    protected $description = 'Alert when failed_jobs is not empty';

    public function handle(FailedJobProviderInterface $failer, Discord $discord): int
    {
        $count = $failer instanceof CountableFailedJobProvider ? $failer->count() : count($failer->all());

        if ($count === 0) {
            $this->components->info('Geen mislukte jobs.');

            return self::SUCCESS;
        }

        $days = (int) config('site-alerts.failed_jobs.retention_days');

        $discord->send($count.' '.($count === 1 ? 'job staat' : 'jobs staan').' in failed_jobs', [
            'Bekijken: `php artisan queue:failed`',
            'Opnieuw: `php artisan queue:retry all`',
            $days > 0 && $failer instanceof PrunableFailedJobProvider ? 'Jobs ouder dan '.$days.' dagen worden automatisch verwijderd' : null,
        ]);

        $this->components->warn($count.' mislukte jobs gemeld.');

        return self::SUCCESS;
    }
}
