<?php

declare(strict_types=1);

if (! function_exists('clicktrail')) {
    /**
     * One obvious entry point:
     *
     *   clicktrail()->capture($request);          // observe this request
     *   clicktrail()->client()->track($event);    // queue a typed event
     *   clicktrail()->pendingPayloads();          // inspect the queue
     */
    function clicktrail(): \ClickTrail\Laravel\ClickTrailManager
    {
        return app(\ClickTrail\Laravel\ClickTrailManager::class);
    }
}
