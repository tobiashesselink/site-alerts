<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use TobiasHesselink\SiteAlerts\Heartbeat;

class HeartbeatTest extends TestCase
{
    public function test_starts_in_the_scheduler_and_hops_through_every_queue_before_the_success_ping(): void
    {
        Queue::fake();

        Heartbeat::start(self::PING_URL.'/', ['default', 'warm']);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::PING_URL.'/start?rid='));
        Queue::assertPushedOn('default', Heartbeat::class, fn (Heartbeat $job) => $job->queues === ['warm']);

        $first = Queue::pushedJobs()[Heartbeat::class][0]['job'];
        $first->handle();
        Queue::assertPushedOn('warm', Heartbeat::class, fn (Heartbeat $job) => $job->queues === []);

        $last = Queue::pushedJobs()[Heartbeat::class][1]['job'];
        $last->handle();
        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL.'?rid='.$first->runId);
    }

    public function test_a_failing_ping_never_fails_the_job(): void
    {
        Sleep::fake();
        Http::fake(['hc-down.test/*' => Http::response(status: 503)]);

        (new Heartbeat('https://hc-down.test/0f1e2d3c', 'run', []))->handle();

        Http::assertSentCount(2);
    }

    public function test_reads_the_queues_from_the_config(): void
    {
        config(['site-alerts.heartbeat.queues' => ' default, warm ,']);

        $this->assertSame(['default', 'warm'], Heartbeat::queues());
    }
}
