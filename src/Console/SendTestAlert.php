<?php

namespace TobiasHesselink\SiteAlerts\Console;

use Illuminate\Console\Command;
use TobiasHesselink\SiteAlerts\Discord;
use TobiasHesselink\SiteAlerts\Heartbeat;

class SendTestAlert extends Command
{
    protected $signature = 'site-alerts:test';

    protected $description = 'Send a test alert to Discord and run one heartbeat';

    public function handle(Discord $discord): int
    {
        $delivered = $discord->send('Testmelding van site-alerts');

        $delivered
            ? $this->components->info('Discord: testmelding verstuurd.')
            : $this->components->error('Discord: niet verstuurd. Staat SITE_ALERTS_DISCORD_WEBHOOK goed? Zie de log.');

        if (! $url = (string) config('site-alerts.heartbeat.url')) {
            $this->components->warn('Heartbeat: SITE_ALERTS_HEARTBEAT_URL is leeg.');

            return $delivered ? self::SUCCESS : self::FAILURE;
        }

        Heartbeat::start($url, Heartbeat::queues());
        $this->components->info('Heartbeat: gestart via queue '.implode(', ', Heartbeat::queues()).'. Controleer de check in Healthchecks.io.');

        return $delivered ? self::SUCCESS : self::FAILURE;
    }
}
