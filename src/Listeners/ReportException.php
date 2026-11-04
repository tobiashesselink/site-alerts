<?php

namespace TobiasHesselink\SiteAlerts\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Throwable;
use TobiasHesselink\SiteAlerts\Discord;

/**
 * Web requests only: jobs and scheduled tasks have their own alerts, and in the console a human is watching.
 * Laravel only reports exceptions worth reporting, so 404s, validation errors and the like never get here.
 */
class ReportException
{
    public function __construct(private Application $app, private Discord $discord) {}

    public function __invoke(Throwable $e): void
    {
        if (! config('site-alerts.exceptions') || $this->app->runningInConsole()) {
            return;
        }

        $location = Discord::location($e);
        $key = 'site-alerts:exception:'.sha1($e::class.'|'.$location);

        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        $request = $this->app->make('request');

        // Long random segments are tokens, such as in a password reset link.
        $path = preg_replace('#/[A-Za-z0-9]{32,}(?=/|$)#', '/…', '/'.ltrim($request->path(), '/'));

        $sent = $this->discord->send('Fout bij '.$request->method().' '.$path, [
            Discord::describe($e),
            $location,
            'Dezelfde fout wordt 24 uur niet opnieuw gemeld',
        ]);

        if (! $sent) {
            Cache::forget($key);
        }
    }
}
