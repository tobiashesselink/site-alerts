<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use TobiasHesselink\SiteAlerts\Heartbeat;

class CommandTest extends TestCase
{
    public function test_reminds_about_failed_jobs(): void
    {
        foreach (range(1, 2) as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
            ]);
        }

        $this->artisan('site-alerts:failed-jobs')->assertSuccessful();

        $message = $this->discordMessages()[0];
        $this->assertStringContainsString('2 jobs staan in failed_jobs', $message);
        $this->assertStringContainsString('ouder dan 30 dagen', $message);
    }

    public function test_stays_quiet_without_failed_jobs(): void
    {
        $this->artisan('site-alerts:failed-jobs')->assertSuccessful();

        $this->assertSame([], $this->discordMessages());
    }

    public function test_test_command_sends_an_alert_and_starts_a_heartbeat(): void
    {
        Queue::fake();
        config(['site-alerts.heartbeat.url' => self::PING_URL, 'site-alerts.heartbeat.queues' => 'default,warm']);

        $this->artisan('site-alerts:test')->assertSuccessful();

        $this->assertStringContainsString('Testmelding van site-alerts', $this->discordMessages()[0]);
        Queue::assertPushedOn('default', Heartbeat::class);
    }

    public function test_test_command_fails_without_webhook(): void
    {
        config(['site-alerts.discord_webhook' => null]);

        $this->artisan('site-alerts:test')->assertFailed();
    }
}
