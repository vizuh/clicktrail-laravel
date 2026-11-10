<?php
/**
 * Standalone assertion runner (no full Laravel runtime needed) - pattern of
 * clicktrail-php/tests/_runner.php. Runs inside the podman wordpress:php8.X-apache
 * container with this repo mounted at /app and the SDK at /sdk:
 *
 *   podman run --rm --security-opt label=disable -v "$PWD":/app \
 *     -v "$PWD/../clicktrail-php":/sdk:ro wordpress:php8.3-apache php /app/tests/_runner.php
 */

namespace Illuminate\Bus {
    trait Queueable
    {
        public function onConnection(string $connection): static
        {
            return $this;
        }

        public function onQueue(string $queue): static
        {
            return $this;
        }
    }
}

namespace Illuminate\Contracts\Queue {
    interface ShouldQueue
    {
    }
}

namespace Illuminate\Queue {
    trait InteractsWithQueue
    {
        /** @var object|null queue job wrapper; null outside a worker, like Laravel */
        public $job;

        public function fail($exception = null): void
        {
            if ($this->job) {
                $this->job->fail($exception);
            }
        }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
    }
}

namespace Illuminate\Container {
    class Container
    {
        /** @param array<string, object> $bindings */
        public function __construct(private array $bindings = [])
        {
        }

        public function bound(string $abstract): bool
        {
            return isset($this->bindings[$abstract]);
        }

        public function make(string $abstract): object
        {
            return $this->bindings[$abstract];
        }
    }
}

// Minimal PSR stubs: BatchClient only type-hints these and calls methods dynamically.
namespace Psr\Http\Client {
    interface ClientInterface
    {
    }
}

namespace Psr\Http\Message {
    interface RequestFactoryInterface
    {
    }

    interface StreamFactoryInterface
    {
    }
}

namespace Psr\Log {
    interface LoggerInterface
    {
    }

    class NullLogger implements LoggerInterface
    {
        public function __call(string $name, array $args): void
        {
        }
    }
}

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

    if (!function_exists('config')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function config(string $key, $default = null)
        {
            return $GLOBALS['clicktrail_test_config'][$key] ?? $default;
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

    // ---- T7: queued-delivery process boundary -----------------------------------
    $missingPayloadsRejected = false;
    try {
        new \ClickTrail\Laravel\Jobs\DeliverEventsJob();
    } catch (\ArgumentCountError) {
        $missingPayloadsRejected = true;
    }

    $inc();
    check($missingPayloadsRejected, 'T7 delivery job requires serialized payloads');

    $payloads = [[
        'event_id' => 'evt_order_42',
        'idempotency_key' => 'evt_order_42',
        'event' => ['name' => 'conversion'],
    ]];
    $job = new \ClickTrail\Laravel\Jobs\DeliverEventsJob($payloads);
    $workerJob = unserialize(serialize($job));

    check($workerJob instanceof \ClickTrail\Laravel\Jobs\DeliverEventsJob, 'T7 delivery job survives serialization');
    check($workerJob->payloads === $payloads, 'T7 payloads and idempotency keys cross the worker boundary unchanged');

    // ---- T8: one worker process, many delivery jobs -----------------------------
    $GLOBALS['clicktrail_test_config'] = ['clicktrail.site_id' => 's1', 'clicktrail.endpoint' => 'https://ingest.example.test/batch'];
    // Fake PSR-18 transport: records every event id it receives; answers with queued statuses (default 200).
    $http = new class implements \Psr\Http\Client\ClientInterface {
        /** @var array<int, string> */
        public array $sent = [];
        /** @var array<int, int> */
        public array $statuses = [];

        public function sendRequest(object $request): object
        {
            foreach (json_decode($request->body, true) as $envelope) {
                $this->sent[] = $envelope['event_id'];
            }
            $status = array_shift($this->statuses) ?? 200;

            return new class ($status) {
                public function __construct(private int $status)
                {
                }

                public function getStatusCode(): int
                {
                    return $this->status;
                }
            };
        }
    };
    $factory = new class implements \Psr\Http\Message\RequestFactoryInterface, \Psr\Http\Message\StreamFactoryInterface {
        public function createRequest(string $method, string $uri): object
        {
            return new class {
                public string $body = '';

                public function withHeader(string $name, string $value): static
                {
                    return $this;
                }

                public function withBody(string $body): static
                {
                    $this->body = $body;

                    return $this;
                }
            };
        }

        public function createStream(string $content): string
        {
            return $content;
        }
    };
    $manager = new \ClickTrail\Laravel\ClickTrailManager(new \Illuminate\Container\Container([
        \Psr\Http\Client\ClientInterface::class => $http,
        \Psr\Http\Message\RequestFactoryInterface::class => $factory,
        \Psr\Http\Message\StreamFactoryInterface::class => $factory,
    ]));
    $queueJob = new class {
        public ?\Throwable $failedWith = null;

        public function fail(?\Throwable $e): void
        {
            $this->failedWith = $e;
        }
    };
    $evt = static fn (string $id): array => ['event_id' => $id, 'idempotency_key' => $id, 'event' => ['name' => 'conversion']];
    $J = \ClickTrail\Laravel\Jobs\DeliverEventsJob::class;

    // Same process (sync driver / Octane / worker dispatching follow-ups): job must not resend the cached queue.
    $manager->client()->restore([$evt('e1')]);
    $job1 = new $J($manager->takePendingPayloads());
    $job1->handle($manager);
    $manager->client()->restore([$evt('e2')]);
    $job2 = new $J($manager->takePendingPayloads());
    $job2->handle($manager);
    $inc();
    check($job2->payloads === [$evt('e2')], 'T8 second dispatch carries only events queued after the first');
    check($http->sent === ['e1', 'e2'], 'T8 two jobs in one process send each event exactly once');
    check($manager->pendingPayloads() === [], 'T8 manager queue is empty after handoff');

    // A failed job's leftovers never leak into the next job on the same worker.
    $http->sent = [];
    $http->statuses = [400];
    $big = array_map(static fn (int $i): array => $evt("big{$i}"), range(1, 21)); // > batchSize (20)
    $jobA = new $J($big);
    $jobA->job = $queueJob;
    $jobA->handle($manager);
    $jobB = new $J([$evt('b1')]);
    $jobB->handle($manager);
    $inc();
    check($queueJob->failedWith instanceof \ClickTrail\Client\Exception\PermanentException, 'T8 permanent failure fails the job (no retry)');
    check(!in_array('big21', $http->sent, true), 'T8 failed job leftovers are not sent by the next job');
    check(array_slice($http->sent, -1) === ['b1'], 'T8 next job sends only its own payloads');

    // Config error (empty site_id) is permanent too.
    $GLOBALS['clicktrail_test_config']['clicktrail.site_id'] = '';
    $queueJob->failedWith = null;
    $jobC = new $J([$evt('c1')]);
    $jobC->job = $queueJob;
    $jobC->handle($manager);
    $inc();
    check($queueJob->failedWith instanceof \ClickTrail\Client\Exception\PermanentException, 'T8 config error fails the job (no retry)');
    $GLOBALS['clicktrail_test_config']['clicktrail.site_id'] = 's1';

    // Retryable failure still propagates so Laravel re-runs the job via backoff().
    $http->statuses = [503, 503, 503, 503]; // first send + 3 SDK retries (~1.4s of SDK usleep)
    $queueJob->failedWith = null;
    $jobD = new $J([$evt('d1')]);
    $jobD->job = $queueJob;
    $retried = false;
    try {
        $jobD->handle($manager);
    } catch (\ClickTrail\Client\Exception\RetryableException) {
        $retried = true;
    }
    $inc();
    check($retried && $queueJob->failedWith === null, 'T8 retryable failure is rethrown, not failed');
    check($jobD->backoff() === [10, 60], 'T8 backoff is whole seconds (Laravel unit)');

    fwrite(STDOUT, "ALL PASS ({$checks} scenarios, 8 groups)\n");
}
