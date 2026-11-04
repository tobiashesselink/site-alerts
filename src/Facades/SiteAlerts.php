<?php

namespace TobiasHesselink\SiteAlerts\Facades;

use Illuminate\Support\Facades\Facade;
use TobiasHesselink\SiteAlerts\Discord;

/**
 * @method static bool send(string $title, array<int, string|null> $lines = [])
 */
class SiteAlerts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Discord::class;
    }
}
