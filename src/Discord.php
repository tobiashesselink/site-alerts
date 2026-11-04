<?php

namespace TobiasHesselink\SiteAlerts;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class Discord
{
    public const MAX_PER_WINDOW = 10;

    public const WINDOW_SECONDS = 600;

    /**
     * Sends one message to the webhook. Never throws: an alert must not break the job, task or request that raised it.
     * Returns false when the message only went to the log (no webhook, rate limited or Discord unreachable).
     *
     * @param  array<int, string|null>  $lines
     */
    public function send(string $title, array $lines = []): bool
    {
        return rescue(fn () => $this->deliver($title, $lines), false, report: false);
    }

    public static function site(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.name');
    }

    /** A QueryException shows the SQL without its bindings, which can hold form data. */
    public static function describe(Throwable $e): string
    {
        $message = $e instanceof QueryException
            ? $e->getPrevious()?->getMessage().' (SQL: '.$e->getSql().')'
            : $e->getMessage();

        return $e::class.': '.str(static::redact($message))->squish()->limit(300);
    }

    /** Hides values that can be secrets: query string values and long random path segments, such as tokens. */
    public static function redact(string $text): string
    {
        return preg_replace(
            ['/([?&][^\s=&?#]+=)[^\s&#`\'"]+/', '#/[A-Za-z0-9]{32,}(?=[/?\s`\'"]|$)#'],
            ['$1…', '/…'],
            $text,
        ) ?? $text;
    }

    /** Where it was thrown and, if that is in vendor, the line in the site's own code that led to it. */
    public static function location(Throwable $e): string
    {
        $thrown = static::path($e->getFile()).':'.$e->getLine();

        if (static::isOwnCode($e->getFile())) {
            return $thrown;
        }

        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && static::isOwnCode($frame['file'])) {
                return $thrown.' (vanuit '.static::path($frame['file']).':'.($frame['line'] ?? 0).')';
            }
        }

        return $thrown;
    }

    public static function path(string $file): string
    {
        return str($file)->after(base_path().DIRECTORY_SEPARATOR)->toString();
    }

    private static function isOwnCode(string $file): bool
    {
        return ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            && ! in_array($file, [base_path('artisan'), public_path('index.php')], true);
    }

    /** @param  array<int, string|null>  $lines */
    private function deliver(string $title, array $lines): bool
    {
        $message = '**'.static::site().'** · '.$title;

        foreach (array_filter($lines) as $line) {
            $message .= "\n- ".$line;
        }

        // Invalid UTF-8 (cut-off output, binary data in an exception) would make the JSON body fail.
        $message = mb_scrub($message, 'UTF-8');

        $webhook = (string) config('site-alerts.discord_webhook');

        if ($webhook === '') {
            Log::warning('[site-alerts] '.$message);

            return false;
        }

        if (RateLimiter::tooManyAttempts('site-alerts', self::MAX_PER_WINDOW)) {
            Log::warning('[site-alerts] '.$message);

            if (RateLimiter::attempt('site-alerts:paused', 1, fn () => true, self::WINDOW_SECONDS)) {
                $this->post($webhook, '**'.static::site().'** · Meer dan '.self::MAX_PER_WINDOW.' meldingen in '.(self::WINDOW_SECONDS / 60).' minuten: de rest staat alleen in de log.');
            }

            return false;
        }

        RateLimiter::hit('site-alerts', self::WINDOW_SECONDS);

        return $this->post($webhook, $message);
    }

    private function post(string $webhook, string $message): bool
    {
        try {
            $response = Http::timeout(5)->post($webhook, [
                'content' => str($message)->limit(1900)->toString(),
                'allowed_mentions' => ['parse' => []],
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning("[site-alerts] Discord gaf status {$response->status()}: {$message}");
        } catch (Throwable $e) {
            Log::warning('[site-alerts] Discord onbereikbaar ('.str_replace($webhook, '[webhook]', $e->getMessage())."): {$message}");
        }

        return false;
    }
}
