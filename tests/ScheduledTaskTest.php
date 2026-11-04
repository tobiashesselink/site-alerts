<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ScheduledTaskTest extends TestCase
{
    public function test_alerts_when_a_task_starts_failing_and_when_it_recovers(): void
    {
        $task = $this->task();
        $task->exitCode = 1;

        event(new ScheduledTaskFailed($task, new RuntimeException('Scheduled command [x] failed with exit code [1].')));
        event(new ScheduledTaskFailed($task, new RuntimeException('Scheduled command [x] failed with exit code [1].')));
        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, 0.1));
        event(new ScheduledTaskFinished($task, 0.1));

        $messages = $this->discordMessages();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Geplande taak mislukt: statistiek:ophalen --als-nodig', $messages[0]);
        $this->assertStringContainsString('- Exitcode 1', $messages[0]);
        $this->assertStringContainsString('Volgende run:', $messages[0]);
        $this->assertStringContainsString('Geplande taak weer gelukt: statistiek:ophalen --als-nodig', $messages[1]);
    }

    public function test_a_finished_event_of_a_failed_run_is_not_a_recovery(): void
    {
        $task = $this->task();
        event(new ScheduledTaskFailed($task, new RuntimeException('failed')));

        $task->exitCode = 1;
        event(new ScheduledTaskFinished($task, 0.1));

        $this->assertCount(1, $this->discordMessages());
    }

    public function test_background_tasks_report_through_their_exit_code(): void
    {
        $task = $this->task()->runInBackground();

        $task->exitCode = 1;
        event(new ScheduledBackgroundTaskFinished($task));
        $task->exitCode = 0;
        event(new ScheduledBackgroundTaskFinished($task));

        $messages = $this->discordMessages();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Exitcode 1', $messages[0]);
        $this->assertStringContainsString('weer gelukt', $messages[1]);
    }

    public function test_the_alert_shows_the_last_lines_of_the_captured_output(): void
    {
        $task = $this->task();
        event(new ScheduledTaskStarting($task));

        File::ensureDirectoryExists(dirname($task->output));
        File::put($task->output, "Ophalen...\n\e[31mGoatCounter gaf 401\e[0m\n");

        event(new ScheduledTaskFailed($task, new RuntimeException('failed')));

        $this->assertStringContainsString('Uitvoer: Ophalen... | GoatCounter gaf 401', $this->discordMessages()[0]);

        File::delete($task->output);
    }

    public function test_unnamed_closures_are_tracked_separately(): void
    {
        $schedule = app(Schedule::class);
        $line = __LINE__ + 1;
        $first = $schedule->call(fn () => null)->hourly();
        $second = $schedule->call(fn () => null)->hourly();

        event(new ScheduledTaskFailed($first, new RuntimeException('Eerste kapot')));
        event(new ScheduledTaskFailed($second, new RuntimeException('Tweede kapot')));
        $first->exitCode = 0;
        event(new ScheduledTaskFinished($first, 0.1));

        $messages = $this->discordMessages();
        $this->assertCount(3, $messages);
        $this->assertMatchesRegularExpression('#Geplande taak mislukt: Closure in \S*tests/ScheduledTaskTest\.php:'.$line.'\n#', $messages[0]);
        $this->assertStringContainsString('Tweede kapot', $messages[1]);
        $this->assertMatchesRegularExpression('#weer gelukt: Closure in \S*tests/ScheduledTaskTest\.php:'.$line.'$#', $messages[2]);
    }

    private function task(): Event
    {
        return app(Schedule::class)->command('statistiek:ophalen --als-nodig')->hourly();
    }
}
