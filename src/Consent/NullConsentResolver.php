<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Consent;

use ClickTrail\Consent\ConsentSnapshot;
use Illuminate\Http\Request;

/**
 * Safe default: every request resolves to null (unknown snapshot of all
 * signals). Per the consent contract unknown = denied, so persistence is
 * always suppressed under this resolver. Use it until a real CMP adapter is
 * wired (or set clicktrail.consent_required=false deliberately).
 */
final class NullConsentResolver implements ConsentResolverInterface
{
    public function resolve(Request $request): ?ConsentSnapshot
    {
        return null;
    }
}
