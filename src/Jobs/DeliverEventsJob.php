<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Jobs;

use ClickTrail\Client\Exception\RetryableException;
use ClickTrail\Laravel\ClickTrailManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable; // TODO verify: Laravel 11 base job trait; drop if unused on L10

final class DeliverEventsJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    public function __construct()
    {
        /** @var mixed $connection */
        $connection = config('clicktrail.queue_connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
        /** @var mixed $queue */
        $queue = config('clicktrail.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * Exponential backoff mirroring the SDK transport's retry ladder.
     *
     * @return array<int, int> milliseconds
     */
    public function backoff(): array
    {
        return [200, 1000, 5000];
    }

    public function handle(ClickTrailManager $manager): void
    {
        // flush() throws RetryableException on 429/5xx/network after the SDK's
        // internal retries, which re-queues this job via backoff(). A
        // PermanentException (other 4xx / config error) fails the job.
        $manager->client()->flush();
    }
}
