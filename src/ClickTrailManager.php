<?php

declare(strict_types=1);

namespace ClickTrail\Laravel;

use ClickTrail\Client\BatchClient;
use ClickTrail\Client\ClientInterface as SdkClientInterface; // structural contract only; see SDK
use ClickTrail\Consent\ConsentBehavior;
use ClickTrail\Consent\ConsentSnapshot;
use ClickTrail\Core\AttributionInput;
use ClickTrail\Core\StoredState;
use ClickTrail\Core\TouchMerger;
use ClickTrail\Laravel\Consent\ConsentResolverInterface;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Bound singleton behind the ClickTrail facade.
 *
 *  - capture(Request): merges this request into first/last-touch state and
 *    persists to the session ONLY when consent permits (unknown = denied).
 *  - client(): BatchClient wired from config + container-provided PSR-18/17
 *    implementations.
 */
final class ClickTrailManager
{
    private ?BatchClient $client = null;

    public function __construct(private readonly Container $app)
    {
    }

    public function capture(Request $request): StoredState
    {
        // Caller owns the clock: millisecond ISO-8601 UTC stamp per core law.
        $clock = static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');

        $input = new AttributionInput(
            query: $request->query(),
            host: strtolower($request->getHost() !== '' ? $request->getHost() : (string) $request->server('SERVER_NAME', '')),
            landingPage: $request->fullUrl(),
            referrer: $request->headers->get('Referer') ?: null,
            touchTimestamp: $clock(),
        );

        $key = (string) config('clicktrail.session_key', 'clicktrail.attribution');
        $storedJson = $request->hasSession() ? $request->session()->get($key) : null;
        $state = TouchMerger::observe(StoredState::fromJson(is_string($storedJson) ? $storedJson : null), $input);

        $snapshot = $this->consentResolver()->resolve($request);
        $allowed = $snapshot !== null && ConsentBehavior::can($snapshot, ConsentSnapshot::CAP_ANALYTICS);
        if (! (bool) config('clicktrail.consent_required', true)) {
            // Operator declares this use needs no CMP consent gating.
            $allowed = true;
        }

        if ($allowed && $request->hasSession()) {
            $request->session()->put($key, $state->toJson());
        }

        return $state;
    }

    public function consentResolver(): ConsentResolverInterface
    {
        return $this->app->make(ConsentResolverInterface::class);
    }

    /**
     * Cached BatchClient - queued events survive between calls within a
     * process so track()-then-dispatch actually batches.
     */
    public function client(): BatchClient
    {
        return $this->client ??= $this->buildClient();
    }

    /** @return array<int, array<string, mixed>> */
    public function pendingPayloads(): array
    {
        return $this->client()->pending();
    }

    /** @param array<int, array<string, mixed>> $payloads */
    public function restorePayloads(array $payloads): void
    {
        $this->client()->restore($payloads);
    }

    private function buildClient(): BatchClient
    {
        foreach ([Psr18ClientInterface::class, RequestFactoryInterface::class, StreamFactoryInterface::class] as $abstract) {
            if (! $this->app->bound($abstract)) {
                throw new RuntimeException(sprintf(
                    'ClickTrail delivery needs %s bound in the container (e.g. Guzzle). Bind all three before dispatching DeliverEventsJob.',
                    $abstract,
                ));
            }
        }

        /** @var string $siteId */
        $siteId = (string) config('clicktrail.site_id', '');
        /** @var string $endpoint */
        $endpoint = (string) config('clicktrail.endpoint', '');

        /** @var mixed $apiKey */
        $apiKey = config('clicktrail.api_key');

        return new BatchClient(
            siteId: $siteId,
            endpoint: $endpoint,
            http: $this->app->make(Psr18ClientInterface::class),
            requestFactory: $this->app->make(RequestFactoryInterface::class),
            streamFactory: $this->app->make(StreamFactoryInterface::class),
            apiKey: is_string($apiKey) && $apiKey !== '' ? $apiKey : null,
        );
    }
}
