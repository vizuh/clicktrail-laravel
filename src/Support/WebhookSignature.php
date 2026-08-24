<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Support;

/**
 * HMAC-SHA256 webhook signature helper with constant-time comparison.
 * Signatures are exchanged as "sha256=<hex>" (bare hex also accepted on verify).
 */
final class WebhookSignature
{
    /** Canonical header name. // TODO verify against ingest/webhook API docs before release */
    public const HEADER = 'X-ClickTrail-Signature';

    public static function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $payload, string $signatureHeader, string $secret): bool
    {
        $provided = strtolower(trim($signatureHeader));
        if ($provided === '') {
            return false;
        }
        if (str_starts_with($provided, 'sha256=')) {
            $provided = substr($provided, 7);
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $provided);
    }
}
