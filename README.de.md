[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/laravel**

Sehen Sie, welche Kampagne, welches Keyword, welche Click-ID und Landingpage jede Formular-Übermittlung und Konversion erzeugt hat — in jeder Laravel-10/11-Anwendung.

</div>

[![CI](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/laravel.svg)](https://packagist.org/packages/clicktrail/laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Index

- [Warum](#warum)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Blade-Ausgabe](#blade-ausgabe)
- [Warteschlangen-Zustellung](#warteschlangen-zustellung)
- [Fehlgeschlagene Events und Replay](#fehlgeschlagene-events-und-replay)
- [Consent](#consent)
- [First-Party-Proxy](#first-party-proxy)
- [Diagnose](#diagnose)
- [Webhook-Signaturen](#webhook-signaturen)
- [Unterschiede](#unterschiede)
- [Testing](#testing)
- [Lizenz](#lizenz)

## Warum

Die meisten Tracking-Pakete speichern, was eine Seite angezeigt hat. ClickTrail beweist, welche Kampagne den Lead oder Verkauf erzeugt hat. Dieses Paket ist ein schlanker Adapter über [clicktrail/php-sdk](https://github.com/vizuh/clicktrail-php): Das SDK übernimmt parse/classify/merge/serialize; Laravel übernimmt Capture-Middleware, consent-geprüfte Session-Persistenz, Zustellung über Warteschlangen, Blade-Ausgabe und Artisan-Diagnose.

## Installation

```bash
composer require clicktrail/laravel
```

Package Auto-Discovery registriert den Service Provider und die `ClickTrail`-Facade. Veröffentlichen Sie die Config und tragen Sie `CLICKTRAIL_SITE_ID` und `CLICKTRAIL_ENDPOINT` in die `.env` ein:

```bash
php artisan vendor:publish --tag=clicktrail-config
```

## Schnellstart

`clicktrail()` ist der einzige offensichtliche Einstiegspunkt (die `ClickTrail`-Facade existiert ebenfalls).

```php
// 1. Capture für die Route-Gruppen registrieren, deren Traffic First-/Last-Touch-
//    State aufbauen soll (routes/web.php):
Route::middleware(['web', 'clicktrail.capture'])->group(function () {
    Route::get('/', fn () => view('welcome'));
});

// 2. Ein Besucher kommt über Google Ads; die Middleware führt den Touch zusammen.
//    Nach dem Request:
session('clicktrail.attribution');
// JSON mit first->source === 'google', gesetztem first->clickIds['gclid'] —
// gespeichert NUR wenn Consent Analytics-Storage erlaubt; unbekannt = verweigert.

// 3. Über den Helper aus eigenem Code prüfen oder zusammenführen:
$state = clicktrail()->capture($request);   // StoredState dieses Requests
clicktrail()->pendingPayloads();            // [] — noch nichts in der Warteschlange

// 4. Bei der Konversion (Formular-Submit, Bestellung) die Zustellung dispatchen:
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
// POST im Batch an CLICKTRAIL_ENDPOINT mit Idempotency-Keys; Retries nach
// 200ms/1s/5s; nichts wird während des Requests selbst gesendet.
```

Ein direkter Besuch danach ändert nichts — der First Touch bleibt, der gespeicherte Last Touch bleibt erhalten. Das ist das Merge-Gesetz des SDK: getestet, nicht versprochen.

## Blade-Ausgabe

```blade
{{-- rendert das First-Party-Loader-<script>-Tag aus der Config --}}
@clicktrailHead

{{-- versteckte Attribution-Inputs innerhalb eines <form>, damit der
     serverseitige Submit source / Click-IDs unverändert trägt --}}
@clicktrailAttribution

{{-- normalisierter Consent-Snapshot als data-ct-consent-*-Attribute --}}
<div @clicktrailConsent>...</div>

{{-- oder als explizite Components --}}
<x-clicktrail::head />
<x-clicktrail::attribution-inputs />
```

## Warteschlangen-Zustellung

Events werden nie während des Requests gesendet. Dispatchen Sie den Delivery-Job aus Ihren eigenen Triggern:

```php
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
// flush() wirft bei 429/5xx/Netzwerk eine RetryableException — Laravel führt
// den Job per backoff([200ms, 1000ms, 5000ms]) erneut aus. Eine
// PermanentException lässt den Job fehlschlagen und schreibt die Payloads
// in die Failed-Events-Tabelle.
```

Der Job nutzt den `BatchClient`; binden Sie daher vor dem Dispatch einen PSR-18-Client als `Psr\Http\Client\ClientInterface` plus PSR-17-Request-/Stream-Factories. Queue-Connection und Queue-Name stammen aus `clicktrail.queue_connection` / `clicktrail.queue`.

## Fehlgeschlagene Events und Replay

Nach erschöpften Retries werden die Payloads unverändert in der Tabelle `clicktrail_failed_events` gespeichert (`clicktrail.persist_failed_events`, Standard `true`). Diagnose stellen, dann über denselben Helper wiedereinreihen:

```php
foreach (\ClickTrail\Laravel\Models\ClickTrailFailedEvent::get() as $row) {
    clicktrail()->restorePayloads(json_decode($row->payload, true));
}
// Die Payloads sind zurück in der BatchClient-Warteschlange; der nächste
// DeliverEventsJob-Lauf sendet sie unverändert — gleiche Idempotency-Keys,
// keine Duplikate.
```

## Consent

ClickTrail ist ein Consent-Konsument, kein CMP. Implementieren Sie `ClickTrail\Laravel\Consent\ConsentResolverInterface` mit Ihrem CMP-Adapter und binden Sie ihn, oder setzen Sie `clicktrail.consent_resolver` auf dessen FQCN. Bis dahin liefert der mitgelieferte `NullConsentResolver` einen unbekannten Snapshot, der überall als verweigert gilt: Es werden keine Identifikatoren persistiert und keine Events zugestellt. `clicktrail.consent_required` auf `false` erklärt diesen Einsatz als CMP-frei.

## First-Party-Proxy (optional)

Mit `CLICKTRAIL_FIRST_PARTY_PROXY=true` registriert der Provider `POST /clicktrail/collect`. Er prüft die Batch-Payload-Struktur minimal und reiht die Zustellung über Ihre eigene Infrastruktur neu ein.

## Diagnose

```bash
php artisan clicktrail:diagnose
```

Prüft Config-Präsenz, Endpoint-Erreichbarkeit (TCP-Flag) und die Auflösung des Consent-Resolvers.

## Webhook-Signaturen

Verifizieren Sie ClickTrail-Webhook-Callbacks mit HMAC-SHA256-Vergleich in konstanter Zeit:

```php
\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $request->header('X-ClickTrail-Signature'), $secret);
// === true nur bei passender Signatur; konstante Zeit, kein Timing-Leak
```

## Unterschiede

| Paket | Was es tut | Abgrenzung |
|---|---|---|
| **combindma/laravel-trail** | Speichert UTMs/Referrers in Cookies | ClickTrail beweist, welche Kampagne den Lead oder Verkauf erzeugt hat: deterministische First-/Last-Touch-Merge-Gesetze, validiert durch Golden Fixtures, die unsere WordPress- und GTM-Engines teilen; consent-geprüfte Persistenz; Batch-Zustellung mit Idempotency-Keys |
| **DirectoryTree/Metrics** | Zählt anonyme Events | Komplementär — ClickTrail verbindet Kampagnen mit Identitäten und Umsatz |

Siehe `../docs/COMPETITOR-NOTES.md` für die vollständige Analyse.

## Testing

```bash
php tests/_runner.php                 # komplette Suite, standalone (kein Kernel-Boot)
vendor/bin/phpunit --testdox          # PHPUnit-Durchlauf (CI, PHP 8.3)
```

Die CI lintet alle Dateien und durchläuft beide Stufen unter PHP 8.1–8.3 (`.github/workflows/ci.yml`).

## Lizenz

MIT — Copyright (c) 2026 Vizuh OÜ
