<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use TobiasHesselink\SiteAlerts\Discord;

class FailedJobTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.connection', 'testing');
    }

    public function test_alerts_once_when_the_worker_gives_up_on_a_job(): void
    {
        FailingJob::dispatch();

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

        $uuid = DB::table('failed_jobs')->value('uuid');
        $this->assertNotNull($uuid);

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Job mislukt: '.FailingJob::class, $messages[0]);
        $this->assertStringContainsString('Queue `default`, 1 poging', $messages[0]);
        $this->assertStringContainsString('RuntimeException: SES is onbereikbaar', $messages[0]);
        $this->assertStringContainsString("`php artisan queue:retry {$uuid}`", $messages[0]);
    }

    public function test_a_pickup_after_a_crashed_worker_is_not_counted_as_an_attempt(): void
    {
        FailingJob::dispatch();
        DB::table('jobs')->update(['attempts' => 2]);

        $this->artisan('queue:work', ['--once' => true, '--tries' => 2])->assertSuccessful();

        $message = $this->discordMessages()[0];
        $this->assertStringContainsString('2 pogingen', $message);
        $this->assertStringContainsString('MaxAttemptsExceededException', $message);
    }

    public function test_no_alert_while_the_job_still_has_attempts_left(): void
    {
        FailingJob::dispatch();

        $this->artisan('queue:work', ['--once' => true, '--tries' => 3])->assertSuccessful();

        $this->assertSame([], $this->discordMessages());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_broken_alert_never_keeps_the_job_out_of_failed_jobs(): void
    {
        $this->app->instance(Discord::class, new class extends Discord
        {
            public function send(string $title, array $lines = []): bool
            {
                throw new RuntimeException('Alert broken');
            }
        });

        FailingJob::dispatch();

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}

class FailingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('SES is onbereikbaar');
    }
}
