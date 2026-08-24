<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ClickTrail site + ingest configuration
    |--------------------------------------------------------------------------
    */

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
