<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use TobiasHesselink\SiteAlerts\SiteAlertsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const WEBHOOK = 'https://discord.test/api/webhooks/1/secret';

    protected const PING_URL = 'https://hc-ping.test/0f1e2d3c';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['discord.test/*' => Http::response(status: 204), 'hc-ping.test/*' => Http::response('OK')]);
    }

    protected function getPackageProviders($app): array
    {
        return [SiteAlertsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://www.example.test');
        $app['config']->set('site-alerts.discord_webhook', self::WEBHOOK);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.failed.database', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    /** @return list<string> */
    protected function discordMessages(): array
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::WEBHOOK))
            ->map(fn (array $pair) => $pair[0]['content'])
            ->values()
            ->all();
    }
}
