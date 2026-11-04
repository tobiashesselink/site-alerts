<?php

namespace TobiasHesselink\SiteAlerts\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\MaxAttemptsExceededException;
use TobiasHesselink\SiteAlerts\Discord;

class ReportFailedJob
{
    public function __construct(private Discord $discord) {}

    /** Must never throw: the worker only writes the job to failed_jobs after this listener. */
    public function handle(JobFailed $event): void
    {
        rescue(fn () => $this->report($event), report: false);
    }

    private function report(JobFailed $event): void
    {
        $job = $event->job;
        $e = $event->exception;

        // A worker that died during the last attempt is only noticed at the next pickup, which never runs the job.
        $attempts = $job->attempts() - ($e::class === MaxAttemptsExceededException::class ? 1 : 0);

        // The plain `database` failer retries by numeric id, which is unknown here.
        $retry = $job->uuid() && ! app('queue.failer') instanceof DatabaseFailedJobProvider ? $job->uuid() : 'all';

        $this->discord->send('Job mislukt: '.$job->resolveName(), [
            'Queue `'.$job->getQueue().'`, '.$attempts.' '.($attempts === 1 ? 'poging' : 'pogingen'),
            Discord::describe($e),
            Discord::location($e),
            'Opnieuw: `php artisan queue:retry '.$retry.'`',
        ]);
    }
}
