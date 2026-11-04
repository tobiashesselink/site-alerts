<?php

namespace TobiasHesselink\SiteAlerts\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class ExceptionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('/formulier', fn () => throw new RuntimeException('Kan formulier niet verwerken'));
        Route::get('/bestaat-niet', fn () => abort(404));
        Route::get('/lijst', fn () => DB::select('select * from ontbreekt where email = ?', ['klant@example.test']));
        Route::get('/detail', fn () => DB::select('select * from ontbreekt where email = ?', ['klant@example.test']));
        Route::get('/wachtwoord/{token}', fn () => throw new RuntimeException('Token verlopen'));
    }

    public function test_alerts_an_unexpected_error_in_a_web_request_once_per_day(): void
    {
        $this->asWebRequest();

        $this->post('/formulier')->assertServerError();
        $this->post('/formulier')->assertServerError();

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Fout bij POST /formulier', $messages[0]);
        $this->assertStringContainsString('RuntimeException: Kan formulier niet verwerken', $messages[0]);
        $this->assertStringContainsString('tests/ExceptionTest.php:', $messages[0]);
    }

    public function test_vendor_errors_are_told_apart_by_the_site_code_that_caused_them(): void
    {
        $this->asWebRequest();

        $this->get('/lijst')->assertServerError();
        $this->get('/detail')->assertServerError();

        $messages = $this->discordMessages();
        $this->assertCount(2, $messages);
        $this->assertMatchesRegularExpression('#/vendor/\S+/Connection\.php:\d+ \(vanuit \S*tests/ExceptionTest\.php:\d+\)#', $messages[0]);
        $this->assertStringContainsString('(SQL: select * from ontbreekt where email = ?)', $messages[0]);
        $this->assertStringNotContainsString('klant@example.test', $messages[0]);
    }

    public function test_hides_tokens_in_the_path(): void
    {
        $this->asWebRequest();

        $this->get('/wachtwoord/'.str_repeat('a1', 32))->assertServerError();

        $this->assertStringContainsString('Fout bij GET /wachtwoord/…', $this->discordMessages()[0]);
    }

    public function test_alerts_again_when_the_first_alert_did_not_arrive(): void
    {
        $this->asWebRequest();
        config(['site-alerts.discord_webhook' => 'https://discord-storing.test/api/webhooks/1/secret']);
        Http::fake(['discord-storing.test/*' => Http::sequence()->push(status: 500)->push(status: 204)]);

        $this->post('/formulier')->assertServerError();
        $this->post('/formulier')->assertServerError();
        $this->post('/formulier')->assertServerError();

        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), 'discord-storing.test')));
    }

    public function test_ignores_errors_laravel_does_not_report(): void
    {
        $this->asWebRequest();

        $this->get('/bestaat-niet')->assertNotFound();

        $this->assertSame([], $this->discordMessages());
    }

    public function test_ignores_errors_in_the_console(): void
    {
        $this->post('/formulier')->assertServerError();

        $this->assertSame([], $this->discordMessages());
    }

    public function test_can_be_switched_off(): void
    {
        $this->asWebRequest();
        config(['site-alerts.exceptions' => false]);

        $this->post('/formulier')->assertServerError();

        $this->assertSame([], $this->discordMessages());
    }

    private function asWebRequest(): void
    {
        (fn () => $this->isRunningInConsole = false)->call($this->app);
    }
}
