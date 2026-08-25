[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/laravel**

See which campaign, keyword, click ID and landing page created each form submission and conversion in any Laravel 10/11 app.

</div>

[![CI](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/laravel.svg)](https://packagist.org/packages/clicktrail/laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Index

- [Why](#why)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Blade output](#blade-output)
- [Queued delivery](#queued-delivery)
- [Failed events and replay](#failed-events-and-replay)
- [Consent](#consent)
- [First-party proxy](#first-party-proxy)
- [Diagnostics](#diagnostics)
- [Webhook signatures](#webhook-signatures)
- [How it differs](#how-it-differs)
- [Testing](#testing)
- [License](#license)

## Why

Most tracking packages store what a page showed. ClickTrail proves which campaign created the lead or sale. This package is a thin adapter over [clicktrail/php-sdk](https://github.com/vizuh/clicktrail-php): the SDK owns parse/classify/merge/serialize; Laravel owns capture middleware, consent-gated session persistence, queued delivery, Blade output, and artisan diagnostics.

## Installation

```bash
composer require clicktrail/laravel
```

Package auto-discovery registers the service provider and the `ClickTrail` facade. Publish the config and fill `CLICKTRAIL_SITE_ID` and `CLICKTRAIL_ENDPOINT` in `.env`:

```bash
php artisan vendor:publish --tag=clicktrail-config
```

## Quick start

`clicktrail()` is the main entry point (a `ClickTrail` facade also exists).

```php
// 1. Register capture on the route groups whose traffic should build
//    first/last-touch state (routes/web.php):
Route::middleware(['web', 'clicktrail.capture'])->group(function () {
    Route::get('/', fn () => view('welcome'));
});

// 2. A visitor arrives from Google Ads; the middleware merges the touch.
//    After the request:
session('clicktrail.attribution');
// JSON with first->source === 'google', first->clickIds['gclid'] set.
// Persisted ONLY when consent permits analytics storage; unknown = denied.

// 3. Inspect or merge from your own code via the helper:
$state = clicktrail()->capture($request);   // StoredState for this request
clicktrail()->pendingPayloads();            // [] means nothing queued yet

// 4. On conversion (form submit, order), queue delivery:
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
// batched POST to CLICKTRAIL_ENDPOINT with idempotency keys; retries after
// 200ms/1s/5s; nothing is sent during the request itself.
```

A direct visit afterwards changes nothing: first touch stays, stored last touch persists. That is the SDK's merge law, tested, not promised.

## Blade output

```blade
{{-- renders the first-party loader <script> tag from config --}}
@clicktrailHead

{{-- hidden attribution inputs inside a <form>, so the server-side
     submit carries source / click IDs verbatim --}}
@clicktrailAttribution

{{-- normalized consent snapshot as data-ct-consent-* attributes --}}
<div @clicktrailConsent>...</div>

{{-- or as explicit components --}}
<x-clicktrail::head />
<x-clicktrail::attribution-inputs />
```

## Queued delivery

Events are never sent during the request. Dispatch the delivery job from your own triggers:

```php
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
// flush() throws RetryableException on 429/5xx/network. Laravel re-runs the
// job via backoff([200ms, 1000ms, 5000ms]). A PermanentException fails the
// job and routes payloads to the failed-events table.
```

The job uses the `BatchClient`, so bind a PSR-18 client as `Psr\Http\Client\ClientInterface` plus PSR-17 request/stream factories before dispatching. Queue connection and queue name come from `clicktrail.queue_connection` / `clicktrail.queue`.

## Failed events and replay

After all retries fail, payloads are stored verbatim in the `clicktrail_failed_events` table (`clicktrail.persist_failed_events`, default `true`). Diagnose, then replay through the same helper:

```php
foreach (\ClickTrail\Laravel\Models\ClickTrailFailedEvent::get() as $row) {
    clicktrail()->restorePayloads(json_decode($row->payload, true));
}
// payloads are back in the BatchClient queue; the next DeliverEventsJob run
// sends them unchanged, same idempotency keys, no duplicates.
```

## Consent

ClickTrail is a consent consumer, not a CMP. Implement `ClickTrail\Laravel\Consent\ConsentResolverInterface` with your CMP adapter and bind it, or set `clicktrail.consent_resolver` to its class name. Until then the shipped `NullConsentResolver` returns an unknown snapshot, which is treated as denied everywhere: no identifiers are persisted and no events are delivered. Setting `clicktrail.consent_required` to `false` declares this use needs no CMP gating.

## First-party proxy (optional)

With `CLICKTRAIL_FIRST_PARTY_PROXY=true` the provider registers `POST /clicktrail/collect`. It minimally validates the batch payload shape and re-queues delivery through your own infrastructure.

## Diagnostics

```bash
php artisan clicktrail:diagnose
```

Checks config presence, endpoint reachability (TCP-level flag), and consent resolver resolution.

## Webhook signatures

Verify ClickTrail webhook callbacks with HMAC-SHA256 constant-time comparison:

```php
\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $request->header('X-ClickTrail-Signature'), $secret);
// === true only when the signature matches; constant-time, no timing leak
```

## How it differs

| Package | What it does | Boundary |
|---|---|---|
| **combindma/laravel-trail** | Stores UTMs/referrers in cookies | ClickTrail proves which campaign created the lead or sale: deterministic first/last-touch merge laws validated by golden fixtures shared with our WordPress and GTM engines, consent-gated persistence, batched delivery with idempotency keys |
| **DirectoryTree/Metrics** | Counts anonymous events | Complementary: ClickTrail connects campaigns to identities and revenue |

See `../docs/COMPETITOR-NOTES.md` for the full analysis.

## Testing

```bash
php tests/_runner.php                 # full suite, standalone (no kernel boot)
vendor/bin/phpunit --testdox          # PHPUnit pass (CI, PHP 8.3)
```

CI lints all files and runs both stages on PHP 8.1–8.3 (`.github/workflows/ci.yml`).

## License

MIT. Copyright (c) 2026 Vizuh OÜ
