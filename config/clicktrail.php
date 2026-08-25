<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ClickTrail site + ingest configuration
    |--------------------------------------------------------------------------
    */

    // Master switch (Trail-pattern): disables capture AND rendering without
    // ripping config out - convenient for local dev / kill-switch.
    'enabled' => env('CLICKTRAIL_ENABLED', true),

    'site_id' => env('CLICKTRAIL_SITE_ID', ''),

    'api_key' => env('CLICKTRAIL_API_KEY'),

    // TODO verify: confirm canonical ingest path against the live events/batch
    // endpoint before first release (NEXT-TASKS.md task 2 remainder).
    'endpoint' => env('CLICKTRAIL_ENDPOINT', 'https://ingest.clicktrail.io/v1/events/batch'),

    // First-party loader script URL rendered by @clicktrailHead / x-clicktrail::head.
    'script_src' => env('CLICKTRAIL_SCRIPT_SRC', ''),

    /*
    |--------------------------------------------------------------------------
    | Behavior
    |--------------------------------------------------------------------------
    */

    // When true (default), persistence and delivery are gated behind the
    // consent resolver; unknown consent counts as denied per the consent
    // compatibility contract. When false, the operator declares that this
    // use needs no CMP gating.
    'consent_required' => env('CLICKTRAIL_CONSENT_REQUIRED', true),

    // Class-string of a ClickTrail\Laravel\Consent\ConsentResolverInterface
    // implementation. Null binds the safe NullConsentResolver (unknown=denied).
    'consent_resolver' => env('CLICKTRAIL_CONSENT_RESOLVER'),

    // Session key holding serialized first/last-touch state.
    'session_key' => 'clicktrail.attribution',

    /*
    |--------------------------------------------------------------------------
    | Capture storage (cookie block, Trail-pattern)
    |--------------------------------------------------------------------------
    */

    'capture' => [
        'cookie_prefix' => env('CLICKTRAIL_COOKIE_PREFIX', 'ct_'),
        // Minutes. 180 days matches the ecosystem norm for attribution cookies.
        'cookie_duration' => env('CLICKTRAIL_COOKIE_DURATION', 60 * 24 * 180),
    ],

    // Persist undeliverable event payloads to the clicktrail_failed_events
    // table when a delivery job exhausts its retries (Metrics-pattern buffered
    // commit; payloads replay via ClickTrailManager::restorePayloads()).
    'persist_failed_events' => env('CLICKTRAIL_PERSIST_FAILED_EVENTS', true),

    // Request attribute carrying the merged StoredState after capture.
    'attribute_key' => 'clicktrail.attribution_state',

    // Queue connection/name used by DeliverEventsJob (null = app default).
    'queue' => env('CLICKTRAIL_QUEUE'),
    'queue_connection' => env('CLICKTRAIL_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | First-party proxy
    |--------------------------------------------------------------------------
    */

    // Registers POST /clicktrail/collect forwarding batches through the queue.
    'first_party_proxy' => env('CLICKTRAIL_FIRST_PARTY_PROXY', false),

];
