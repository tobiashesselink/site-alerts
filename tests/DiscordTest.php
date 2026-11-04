<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TobiasHesselink\SiteAlerts\Discord;
use TobiasHesselink\SiteAlerts\Facades\SiteAlerts;

class DiscordTest extends TestCase
{
    public function test_sends_title_and_lines_with_the_site_and_without_mentions(): void
    {
        $this->assertTrue(SiteAlerts::send('Titel', ['Regel 1', null, 'Regel 2']));

        $this->assertSame(["**www.example.test** · Titel\n- Regel 1\n- Regel 2"], $this->discordMessages());
        Http::assertSent(fn (Request $request) => $request['allowed_mentions'] === ['parse' => []]);
    }

    public function test_invalid_utf8_does_not_break_the_alert(): void
    {
        $this->assertTrue(app(Discord::class)->send('Titel', ["Uitvoer: caf\xC3"]));
    }

    public function test_without_webhook_the_alert_goes_to_the_log(): void
    {
        config(['site-alerts.discord_webhook' => null]);
        Log::spy();

        $this->assertFalse(app(Discord::class)->send('Titel'));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('[site-alerts] **www.example.test** · Titel');
    }

    public function test_pauses_after_the_limit_with_one_notice_and_logs_the_rest(): void
    {
        Log::spy();

        foreach (range(1, Discord::MAX_PER_WINDOW + 5) as $i) {
            app(Discord::class)->send("Melding {$i}");
        }

        $messages = $this->discordMessages();
        $this->assertCount(Discord::MAX_PER_WINDOW + 1, $messages);
        $this->assertStringContainsString('de rest staat alleen in de log', end($messages));
        Log::shouldHaveReceived('warning')->times(5);
    }

    public function test_never_throws_and_hides_the_webhook_when_discord_is_unreachable(): void
    {
        Http::fake(['discord.test/*' => fn () => throw new ConnectionException('cURL error for '.self::WEBHOOK)]);
        Log::spy();

        $this->assertFalse(app(Discord::class)->send('Titel'));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '[webhook]') && ! str_contains($message, 'secret'));
    }
}
