<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Jobs;

use ClickTrail\Client\Exception\RetryableException;
use ClickTrail\Laravel\ClickTrailManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverEventsJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    /** @var int */
    public $tries = 3;

    /**
     * Envelopes captured from the manager client at dispatch time, restored
     * into the job's client before flush (Metrics-pattern buffered commit).
     *
     * @var array<int, array<string, mixed>>
     */
    public readonly array $payloads;

    /**
     * @param array<int, array<string, mixed>> $payloads
     */
    public function __construct(array $payloads)
    {
        $this->payloads = $payloads;

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
        if ($this->payloads !== []) {
            $manager->restorePayloads($this->payloads);
        }
        // flush() throws RetryableException on 429/5xx/network after the SDK's
        // internal retries; Laravel re-runs this job via backoff(). A
        // PermanentException (other 4xx / config error) fails the job and
        // routes payloads to the failed-events table below.
        $manager->client()->flush();
    }

    /**
     * Failed-event persistence (contract: NEXT-TASKS task 2 / Metrics-inspired
     * buffered commit). Payloads are stored verbatim so they can be replayed
     * with ClickTrailManager::restorePayloads() after diagnosis.
     */
    public function failed(Throwable $e): void
    {
        if (! (bool) config('clicktrail.persist_failed_events', true)) {
            return;
        }
        try {
            DB::table('clicktrail_failed_events')->insert([
                'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'event' => 'deliver_batch',
                'payload' => json_encode($this->payloads, JSON_THROW_ON_ERROR),
                'exception' => mb_substr($e->getMessage(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (Throwable $persistError) {
            report($persistError); // never mask the original delivery failure
        }
    }
}
