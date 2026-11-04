<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ScheduleTest extends TestCase
{
    public function test_defaults_weekly_reminder_and_thirty_day_retention_without_heartbeat(): void
    {
        $events = $this->events();

        $this->assertSame('0 9 * * 1', $events['site-alerts:failed-jobs']->expression);
        $this->assertSame('30 4 * * *', $events['queue:prune-failed']->expression);
        $this->assertStringContainsString('--hours=720', $events['queue:prune-failed']->command);
        $this->assertArrayNotHasKey('site-alerts:heartbeat', $events);
    }

    #[DefineEnvironment('heartbeatDailyAndKeepForever')]
    public function test_heartbeat_daily_reminder_and_no_pruning(): void
    {
        $events = $this->events();

        $this->assertSame('*/5 * * * *', $events['site-alerts:heartbeat']->expression);
        $this->assertSame('0 9 * * *', $events['site-alerts:failed-jobs']->expression);
        $this->assertArrayNotHasKey('queue:prune-failed', $events);
    }

    #[DefineEnvironment('reminderOff')]
    public function test_reminder_off(): void
    {
        $this->assertArrayNotHasKey('site-alerts:failed-jobs', $this->events());
    }

    protected function heartbeatDailyAndKeepForever($app): void
    {
        $app['config']->set('site-alerts.heartbeat.url', self::PING_URL);
        $app['config']->set('site-alerts.failed_jobs.reminder', 'daily');
        $app['config']->set('site-alerts.failed_jobs.retention_days', 0);
    }

    protected function reminderOff($app): void
    {
        $app['config']->set('site-alerts.failed_jobs.reminder', 'off');
    }

    /** @return array<string, Event> */
    private function events(): array
    {
        return collect(app(Schedule::class)->events())
            ->keyBy(fn (Event $event) => $event->command ? preg_replace("/^.*'artisan' (\S+).*$/", '$1', $event->command) : $event->description)
            ->all();
    }
}
