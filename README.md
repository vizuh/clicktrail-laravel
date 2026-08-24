# clicktrail/laravel

**See which campaign, keyword, click ID and landing page created each form
submission and conversion — in any Laravel 10/11 app.**

Thin Laravel adapter over [clicktrail/php-sdk](https://github.com/vizuh/clicktrail-php)
(the deterministic attribution core). The SDK owns parse/classify/merge/serialize;
this package owns Laravel effects: capture middleware, session persistence gated by
consent, queued event delivery, Blade output, and artisan diagnostics.

## Install

```bash
composer require clicktrail/laravel
```

Package auto-discovery registers the service provider and the `ClickTrail` facade.

## Setup

Publish the config and fill `CLICKTRAIL_SITE_ID`, `CLICKTRAIL_ENDPOINT` (and
optionally `CLICKTRAIL_API_KEY`, `CLICKTRAIL_SCRIPT_SRC`) in `.env`:

```bash
php artisan vendor:publish --tag=clicktrail-config
```

Register the capture middleware (alias `clicktrail.capture`) on the route groups
whose traffic should build first/last-touch state. It persists to the session only
when the resolved consent snapshot permits analytics storage (unknown counts as
denied).

```php
Route::middleware(['web', 'clicktrail.capture'])->group(function () {
    // ...
});
```

## Blade

```blade
{{-- first-party loader script tag from config --}}
@clicktrailHead

{{-- hidden attribution inputs inside a <form> --}}
@clicktrailAttribution

{{-- normalized consent snapshot as data-ct-consent-* attributes --}}
<div @clicktrailConsent>...</div>

{{-- or as explicit components --}}
<x-clicktrail::head />
<x-clicktrail::attribution-inputs />
```

## Queued delivery

Events are never sent during the request. Dispatch the delivery job from your own
triggers (form submits, orders):

```php
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
```

The job flushes the `BatchClient` queue with exponential backoff (`tries=3`,
backoff `[200ms, 1000ms, 5000ms]`). Bind a PSR-18 client as
`Psr\Http\Client\ClientInterface` plus PSR-17 request/stream factories for it
to use.

## Consent

ClickTrail is a consent consumer, not a CMP. Implement
`ClickTrail\Laravel\Consent\ConsentResolverInterface` with your CMP adapter and
bind it, or set `clicktrail.consent_resolver` to its class name. Until then the
shipped `NullConsentResolver` returns an unknown snapshot, which is treated as
denied everywhere: no identifiers are persisted and no events are delivered.

## First-party proxy (optional)

With `CLICKTRAIL_FIRST_PARTY_PROXY=true` the provider registers
`POST /clicktrail/collect`, which minimally validates a batch payload shape and
re-queues delivery through your own infrastructure. Live endpoint verification is
deferred — see NEXT-TASKS.md task 2.

## Diagnostics

```bash
php artisan clicktrail:diagnose
```

Checks config presence, endpoint reachability (TCP-level flag), and consent
resolver resolution.

## Webhook signatures

Verify ClickTrail webhook callbacks with HMAC-SHA256 constant-time comparison:

```php
\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $request->header('X-ClickTrail-Signature'), $secret);
```

## License

MIT — Copyright (c) 2026 Vizuh OÜ
