# site-alerts

Meldingen in Discord en een heartbeat naar Healthchecks.io voor Laravel- en Statamic-sites.

Op een site met een queue gaat veel stil mis: een formuliermail die na alle pogingen in `failed_jobs`
belandt, een nachtelijke taak die faalt, een worker die stilvalt. Je merkt het pas als een klant belt.
Dit package meldt het direct, voor elke site op dezelfde manier en zonder eigen code per site.

## Wat het meldt

| Situatie | Melding |
|---|---|
| Job definitief mislukt (staat in `failed_jobs`) | Direct, één keer per job, met het `queue:retry`-commando |
| `failed_jobs` is niet leeg | Herinnering: wekelijks (standaard), dagelijks of uit |
| Geplande taak mislukt of weer gelukt | Bij elke statuswissel, met de laatste regels uitvoer |
| Onverwachte fout in een webrequest | Eén keer per fout per 24 uur (geen 404's of validatiefouten) |
| Cron, worker of server ligt eruit | Via Healthchecks.io: de heartbeat blijft uit |

- Maximaal 10 Discord-meldingen per 10 minuten per site; daarboven één melding dat de rest in de log staat.
- Zonder webhook gaat elke melding naar de log, zodat er nooit iets stil verdwijnt.
- Een melding bevat nooit de payload van een job, de waarden in een SQL-query, of querystrings en tokens uit een URL.
- Een melding kan nooit zelf een fout veroorzaken in de job, taak of request waar hij vandaan komt.

## Installatie

```bash
composer require tobiashesselink/site-alerts
```

In de `.env`:

```dotenv
SITE_ALERTS_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
SITE_ALERTS_HEARTBEAT_URL=https://hc-ping.com/<uuid>
SITE_ALERTS_HEARTBEAT_QUEUES=default
```

Daarna `php artisan config:cache` en `php artisan site-alerts:test`.

Nodig: de Laravel-scheduler (`schedule:run` elke minuut) en een queue-worker.

## Healthchecks.io

Maak per site één check aan:

| Veld | Waarde |
|---|---|
| Schedule | Simple |
| Period | 5 minutes |
| Grace Time | 10 minutes |

Zet de ping-URL in `SITE_ALERTS_HEARTBEAT_URL`. Het meldkanaal (bijvoorbeeld Discord) stel je in Healthchecks.io in.

Elke 5 minuten stuurt de scheduler `/start` en zet hij een job in de queue. Die job gaat door elke queue uit
`SITE_ALERTS_HEARTBEAT_QUEUES` en stuurt pas daarna de success-ping. Komt er niets binnen, dan ligt de cron,
PHP of de server eruit. Komt `start` wel binnen maar success niet, dan staat een worker stil of loopt hij
meer dan 10 minuten achter. Zet in `SITE_ALERTS_HEARTBEAT_QUEUES` dus precies de queues van je workers,
bijvoorbeeld `default,warm` of `default,integrations`.

## Instellingen

| Variabele | Standaard | |
|---|---|---|
| `SITE_ALERTS_DISCORD_WEBHOOK` | leeg | Leeg: alleen naar de log |
| `SITE_ALERTS_HEARTBEAT_URL` | leeg | Leeg: geen heartbeat |
| `SITE_ALERTS_HEARTBEAT_QUEUES` | `default` | Komma-gescheiden |
| `SITE_ALERTS_FAILED_JOBS_REMINDER` | `weekly` | `daily` (elke dag 09:00), `weekly` (maandag 09:00) of `off` |
| `SITE_ALERTS_FAILED_JOBS_RETENTION_DAYS` | `30` | Daarna verwijdert `queue:prune-failed` ze (dagelijks 04:30). `0`: nooit |
| `SITE_ALERTS_EXCEPTIONS` | `true` | Fouten in webrequests melden |

## Commands

- `php artisan site-alerts:test`: stuurt een testmelding naar Discord en start één heartbeat.
- `php artisan site-alerts:failed-jobs`: meldt hoeveel jobs er in `failed_jobs` staan.

## Eigen meldingen

```php
use TobiasHesselink\SiteAlerts\Facades\SiteAlerts;

SiteAlerts::send('Betaling niet verwerkt', ['Order 1042', 'Mollie gaf status 500']);
```

`send()` gooit nooit een exception en geeft `false` als de melding alleen in de log is beland.

## Goed om te weten

- Hoe vaak een job opnieuw wordt geprobeerd, bepaalt de worker (`--tries`, `--backoff`) of de job zelf.
  Het package meldt pas als Laravel opgeeft.
- De uitvoer van geplande commands wordt per run opgeslagen in `storage/logs/schedule-*.log`, zodat een
  melding de echte fout toont en niet alleen de exitcode.
- De status van geplande taken en de 24-uursfilter voor fouten staan in de cache. Na `cache:clear` meldt
  een taak die nog steeds faalt zich opnieuw.
- Fouten in queue-jobs en geplande taken komen niet als foutmelding binnen. Die lopen via hun eigen melding.

## Ontwikkelen

```bash
composer test
composer lint
```
