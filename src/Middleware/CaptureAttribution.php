<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Middleware;

use ClickTrail\Laravel\ClickTrailManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware alias "clicktrail.capture".
 *
 * Wraps the deterministic core (TouchParser/TouchMerger via the manager):
 * builds AttributionInput from the request with an injected clock, gates
 * session persistence through the ConsentResolverInterface (unknown consent =
 * denied), and stores merged state under the configured session key. The
 * merged StoredState is also attached as a request attribute for downstream
 * handlers.
 *
 * CONTRACT: no remote HTTP calls happen inside the request; delivery belongs
 * to Jobs\DeliverEventsJob on the queue worker. Run AFTER the web/session
 * middleware group so the session store exists.
 */
final class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ClickTrailManager $manager */
        $manager = app(ClickTrailManager::class);

        $state = $manager->capture($request);

        /** @var string $attributeKey */
        $attributeKey = config('clicktrail.attribute_key', 'clicktrail.attribution_state');
        $request->attributes->set($attributeKey, $state);

        return $next($request);
    }
}
