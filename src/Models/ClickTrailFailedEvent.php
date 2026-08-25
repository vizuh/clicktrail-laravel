<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Undeliverable batch storage (Metrics-pattern buffered commit). Replay via
 * ClickTrailManager::restorePayloads(json_decode($row->payload, true)).
 *
 * @property string $uuid
 * @property string $event
 * @property string $payload
 * @property string $exception
 */
final class ClickTrailFailedEvent extends Model
{
    protected $table = 'clicktrail_failed_events';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];
}
