<?php

namespace TobiasHesselink\SiteAlerts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The scheduler sends `/start`, then this job hops through every configured queue and sends the success ping from the
 * last one. No ping: cron, PHP or the server is down. Start without success: a worker is down or lagging behind.
 */
class Heartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    /** @param  list<string>  $queues */
    public function __construct(public string $url, public string $runId, public array $queues) {}

    /** @param  list<string>  $queues */
    public static function start(string $url, array $queues): void
    {
        $url = rtrim($url, '/');
        $runId = (string) Str::uuid();

        static::ping($url.'/start', $runId);
        static::hop($url, $runId, $queues === [] ? ['default'] : $queues);
    }

    /** @return list<string> */
    public static function queues(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('site-alerts.heartbeat.queues')))));
    }

    public function handle(): void
    {
        $this->queues === []
            ? static::ping($this->url, $this->runId)
            : static::hop($this->url, $this->runId, $this->queues);
    }

    /** @param  non-empty-list<string>  $queues */
    private static function hop(string $url, string $runId, array $queues): void
    {
        $queue = array_shift($queues);

        dispatch(new self($url, $runId, $queues))->onQueue($queue);
    }

    /** Short timeouts: the `/start` ping runs inside schedule:run and holds up the tasks after it. */
    private static function ping(string $url, string $runId): void
    {
        try {
            Http::timeout(3)->retry(2, 500, throw: false)->get($url, ['rid' => $runId]);
        } catch (Throwable $e) {
            Log::warning('[site-alerts] Heartbeat-ping mislukt: '.str_replace($url, '[ping-url]', $e->getMessage()));
        }
    }
}
