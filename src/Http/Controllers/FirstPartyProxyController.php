<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Http\Controllers;

use ClickTrail\Laravel\ClickTrailManager;
use ClickTrail\Laravel\Jobs\DeliverEventsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stub first-party collection endpoint: POST /clicktrail/collect.
 * Registered by the service provider only when clicktrail.first_party_proxy=true.
 * Validates the batch payload shape minimally and forwards delivery through
 * the queued BatchClient flush.
 */
final class FirstPartyProxyController
{
    public function __invoke(Request $request): Response
    {
        abort_unless((bool) config('clicktrail.first_party_proxy', false) && (bool) config('clicktrail.enabled', true), 404);

        $payload = $request->json()->all();

        if (! isset($payload['events']) || ! is_array($payload['events'])) {
            return new JsonResponse(['error' => 'invalid_payload', 'detail' => 'body must be a JSON object with an "events" array'], 422);
        }

        /** @var ClickTrailManager $manager */
        $manager = app(ClickTrailManager::class);

        // Raw event arrays are re-validated by the SDK on flush.
        // TODO verify: raw-payload -> envelope mapping once the live
        // events/batch contract is verified (NEXT-TASKS.md task 2 remainder).
        foreach ($payload['events'] as $event) {
            if (! is_array($event)) {
                continue;
            }
            $client = $manager->client();
            $client->restore([$event]);
        }

        DeliverEventsJob::dispatch($manager->pendingPayloads());

        return new JsonResponse(['queued' => true], 202);

        // DEFERRED - Phase P0.5+ (reason: live events/batch endpoint verification pending; see NEXT-TASKS.md task 2)
    }
}
