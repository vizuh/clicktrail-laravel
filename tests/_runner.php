<?php
/**
 * Standalone assertion runner (no full Laravel runtime needed) - pattern of
 * clicktrail-php/tests/_runner.php. Runs inside the podman wordpress:php8.X-apache
 * container with this repo mounted at /app and the SDK at /sdk:
 *
 *   podman run --rm --security-opt label=disable -v "$PWD":/app \
 *     -v "$PWD/../clicktrail-php":/sdk:ro wordpress:php8.3-apache php /app/tests/_runner.php
 */

namespace {
    error_reporting(E_ALL);

    // env() stub so config/clicktrail.php evaluates outside Laravel.
    if (!function_exists('env')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function env(string $key, $default = null)
        {
            return $default;
        }
    }

    $appRoot = getenv('CLICKTRAIL_APP_ROOT') ?: '/app';
    $sdkRoot = getenv('CLICKTRAIL_SDK_ROOT') ?: '/sdk';

    spl_autoload_register(function ($class) use ($appRoot, $sdkRoot): void {
        $map = [
            'ClickTrail\\Laravel\\' => $appRoot . '/src/',
            'ClickTrail\\Core\\' => $sdkRoot . '/src/Core/',
            'ClickTrail\\Consent\\' => $sdkRoot . '/src/Consent/',
            'ClickTrail\\Conventions\\' => $sdkRoot . '/src/Conventions/',
            'ClickTrail\\' => $sdkRoot . '/src/', // Client + anything else in the SDK
        ];
        foreach ($map as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $f = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($f)) {
                    require $f;

                    return;
                }
            }
        }
    });

    function check(bool $cond, string $msg): void
    {
        if (!$cond) {
            fwrite(STDERR, "FAIL: {$msg}\n");
            exit(1);
        }
    }

    $checks = 0;
    $inc = static function () use (&$checks): void {
        $checks++;
    };

    // ---- T1: config defaults -------------------------------------------------
    /** @var array<string, mixed> $cfg */
    $cfg = require $appRoot . '/config/clicktrail.php';
    $inc();
    check(is_array($cfg), 'T1 config returns array');
    check($cfg['consent_required'] === true, 'T1 consent_required defaults true');
    check($cfg['first_party_proxy'] === false, 'T1 first_party_proxy defaults false');
    check($cfg['site_id'] === '', 'T1 site_id defaults empty');
    check($cfg['api_key'] === null, 'T1 api_key defaults null');
    check(is_string($cfg['endpoint']) && str_starts_with((string) $cfg['endpoint'], 'https://'), 'T1 endpoint https default');
    check(is_string($cfg['session_key']) && $cfg['session_key'] !== '', 'T1 session_key set');
    check($cfg['consent_resolver'] === null, 'T1 consent_resolver defaults null');

    // ---- T2: consent contract wiring ------------------------------------------
    $inc();
    check(interface_exists(\ClickTrail\Laravel\Consent\ConsentResolverInterface::class), 'T2 resolver interface exists');
    check(is_subclass_of(\ClickTrail\Laravel\Consent\NullConsentResolver::class, \ClickTrail\Laravel\Consent\ConsentResolverInterface::class), 'T2 NullConsentResolver implements interface');
    // Capture middleware + delivery job reference illuminate/* traits and are
    // covered by php -l; they need a full Laravel runtime to instantiate.

    // ---- T3: hidden-input field order shape -----------------------------------
    $order = \ClickTrail\Laravel\Support\AttributionFields::HIDDEN_FIELD_ORDER;
    $inc();
    check(count($order) === 23, 'T3 exactly 23 canonical fields');
    check(array_slice($order, 0, 4) === ['visitor_id', 'session_id', 'event_id', 'site_id'], 'T3 ids lead the order');
    check(array_slice($order, 4, 6) === ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'], 'T3 six utm_ fields');
    check(array_slice($order, 10, 10) === \ClickTrail\Conventions\Stable::CLICK_ID_KEYS, 'T3 10 click ids match SDK Stable parity');
    check(array_slice($order, 20, 3) === ['landing_page', 'initial_referrer', 'consent_state'], 'T3 trailing trio');

    // ---- T4: flatten output shape ----------------------------------------------
    $touch = new \ClickTrail\Core\Touch(
        source: 'google',
        medium: 'cpc',
        campaign: 'summer',
        referrer: 'https://news.example.com/post',
        landingPage: 'https://shop.example.com/promo?gclid=XYZ',
        clickIds: ['gclid' => 'XYZ', 'fbclid' => 'FB9'],
    );
    $state = new \ClickTrail\Core\StoredState(first: $touch, last: $touch);
    $flat = \ClickTrail\Laravel\Support\AttributionFields::flatten($state, null, ['site_id' => 's1', 'visitor_id' => 'v1']);
    $inc();
    check(array_keys($flat) === array_values(array_intersect($order, array_keys($flat))), 'T4 output keys follow HIDDEN_FIELD_ORDER strictly');
    check(($flat['utm_source'] ?? '') === 'google', 'T4 utm_source flattened');
    check(($flat['utm_medium'] ?? '') === 'cpc', 'T4 utm_medium flattened');
    check(($flat['gclid'] ?? '') === 'XYZ', 'T4 gclid flattened');
    check(($flat['fbclid'] ?? '') === 'FB9', 'T4 fbclid flattened');
    check(($flat['landing_page'] ?? '') === 'https://shop.example.com/promo?gclid=XYZ', 'T4 landing_page flattened');
    check(($flat['initial_referrer'] ?? '') === 'https://news.example.com/post', 'T4 initial_referrer flattened');
    check(($flat['consent_state'] ?? '') === 'unknown', 'T4 consent_state unknown when snapshot null');
    check(!isset($flat['utm_content']) && !isset($flat['ttclid']), 'T4 empty values skipped');

    $emptyFlat = \ClickTrail\Laravel\Support\AttributionFields::flatten(new \ClickTrail\Core\StoredState());
    $inc();
    check($emptyFlat === ['consent_state' => 'unknown'], 'T4 empty state yields consent_state only');

    // ---- T5: webhook signature ---------------------------------------------------
    $secret = 'whsec_test_123';
    $payload = '{"event":"sale.completed"}';
    $sig = \ClickTrail\Laravel\Support\WebhookSignature::sign($payload, $secret);
    $inc();
    check(str_starts_with($sig, 'sha256='), 'T5 sign prefixed sha256=');
    check(\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $sig, $secret), 'T5 verify true on valid signature');
    check(\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, substr($sig, 7), $secret), 'T5 verify accepts bare hex');
    check(!\ClickTrail\Laravel\Support\WebhookSignature::verify($payload . ' ', $sig, $secret), 'T5 verify false on tampered payload');
    check(!\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $sig, $secret . 'x'), 'T5 verify false on wrong secret');
    check(!\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, '', $secret), 'T5 verify false on empty header');

    // ---- T6: snippet renderer -----------------------------------------------------
    $R = \ClickTrail\Laravel\Support\SnippetRenderer::class;
    $html = $R::head([
        'script_src' => 'https://cdn.clicktrail.io/ct.js',
        'site_id' => 's1',
        'api_key' => 'SECRET-VALUE',
        'consent_required' => true,
    ]);
    $inc();
    check(str_contains($html, '<script src="https://cdn.clicktrail.io/ct.js"'), 'T6 script tag rendered');
    check(str_contains($html, 'data-ct-site-id="s1"'), 'T6 site id as data attribute');
    check(!str_contains($html, 'SECRET-VALUE') && !str_contains($html, 'api-key'), 'T6 server-only keys never leak into markup');
    check($R::head(['script_src' => '']) === '', 'T6 no script_src -> empty output');
    check($R::head(['script_src' => 'x"><script>alert(1)</script>']) === '<script src="x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;" async></script>', 'T6 src escaped');

    $attrs = $R::consentState([
        'analytics_storage' => 'granted',
        'ad_user_data' => 'denied',
        'ad_personalization' => null,
    ]);
    $inc();
    check(str_contains($attrs, 'data-ct-consent-analytics-storage="granted"'), 'T6 analytics attr rendered');
    check(str_contains($attrs, 'data-ct-consent-ad-user-data="denied"'), 'T6 ad_user_data attr rendered');
    check(!str_contains($attrs, 'ad-personalization'), 'T6 missing signal skipped');

    fwrite(STDOUT, "ALL PASS ({$checks} scenarios, 6 groups)\n");
}
