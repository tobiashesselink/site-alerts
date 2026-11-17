<?php

namespace TobiasHesselink\SiteAlerts\Listeners;

use Closure;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionProperty;
use TobiasHesselink\SiteAlerts\Discord;

/**
 * Alerts on state changes only: once when a task starts failing and once when it succeeds again.
 * Missed runs (cron or server down) are caught by the heartbeat, not here. The state lives in a file in storage,
 * not in the cache: deploys run cache:clear, and a task that keeps failing would then alert again after every deploy.
 */
class ReportScheduledTasks
{
    public function __construct(private Discord $discord) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskStarting::class => 'starting',
            ScheduledTaskFailed::class => 'failed',
            ScheduledTaskFinished::class => 'finished',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
        ];
    }

    /** Capture command output so a failure alert can show the actual error instead of just the exit code. */
    public function starting(ScheduledTaskStarting $event): void
    {
        if ($event->task->command) {
            rescue(fn () => $event->task->storeOutput(), report: false);
        }
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $task = $event->task;

        $this->markFailing($task, $task->command && $task->exitCode ? 'Exitcode '.$task->exitCode : $event->exception->getMessage());
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        if (! $event->task->runInBackground && $event->task->exitCode === 0) {
            $this->markRecovered($event->task);
        }
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $event->task->exitCode === 0
            ? $this->markRecovered($event->task)
            : $this->markFailing($event->task, 'Exitcode '.$event->task->exitCode);
    }

    /** Never throws: an exception here would stop the remaining tasks of this scheduler run. */
    private function markFailing(Event $task, string $error): void
    {
        rescue(fn () => $this->reportFailing($task, $error), report: false);
    }

    private function markRecovered(Event $task): void
    {
        rescue(fn () => $this->reportRecovered($task), report: false);
    }

    private function reportFailing(Event $task, string $error): void
    {
        $state = static::state($task);

        if (is_file($state)) {
            return;
        }

        $sent = $this->discord->send('Geplande taak mislukt: '.static::name($task), [
            str(Discord::redact($error))->squish()->limit(300)->toString(),
            ($output = static::output($task)) ? 'Uitvoer: '.$output : null,
            'Volgende run: '.$task->nextRunDate()->timezone(config('app.timezone'))->format('d-m H:i'),
        ]);

        if ($sent) {
            File::ensureDirectoryExists(dirname($state));
            File::put($state, static::name($task));
        }
    }

    private function reportRecovered(Event $task): void
    {
        $state = static::state($task);

        if (is_file($state) && $this->discord->send('Geplande taak weer gelukt: '.static::name($task))) {
            File::delete($state);
        }
    }

    /** Exists while the task is failing (and that has been reported). */
    private static function state(Event $task): string
    {
        return storage_path('framework/site-alerts/schedule-'.sha1($task->mutexName().'|'.static::name($task)).'.failing');
    }

    private static function name(Event $task): string
    {
        if ($task->command) {
            return Str::after($task->command, "'artisan' ");
        }

        if ($task->description || ! $task instanceof CallbackEvent) {
            return $task->getSummaryForDisplay();
        }

        // Unnamed callbacks share one mutex name, so tell them apart by where they are defined.
        $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue($task);

        if (! $callback instanceof Closure) {
            return is_string($callback) ? $callback : get_debug_type($callback);
        }

        $function = new ReflectionFunction($callback);

        return 'Closure in '.Discord::path((string) $function->getFileName()).':'.$function->getStartLine();
    }

    /** Last lines of the captured output, also in the separate `schedule:finish` process of a background task. */
    private static function output(Event $task): ?string
    {
        $path = $task->output && $task->output !== $task->getDefaultOutput()
            ? $task->output
            : storage_path('logs/schedule-'.sha1($task->mutexName()).'.log');

        if (! $task->command || ! is_file($path)) {
            return null;
        }

        $tail = (string) file_get_contents($path, offset: max(0, filesize($path) - 2000));
        $lines = collect(preg_split('/\R/', (string) preg_replace('/\e\[[\d;]*m/', '', $tail)))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->take(-5);

        return $lines->isEmpty() ? null : str(Discord::redact($lines->implode(' | ')))->limit(600)->toString();
    }
}
