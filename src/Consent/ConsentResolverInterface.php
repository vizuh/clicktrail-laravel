<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Consent;

use ClickTrail\Consent\ConsentSnapshot;
use Illuminate\Http\Request;

/**
 * Adapter-facing consent source for Laravel. CMP-specific logic (WP Consent
 API bridge, CookieYes, Cookiebot, iubenda, ...) lives behind this interface;
 * capture and delivery code only ever sees the normalized
 * ClickTrailConsentSnapshot.
 *
 * Contract: return null when no decision is known. Null means "unknown",
 * which is denied by default per the consent compatibility contract.
 */
interface ConsentResolverInterface
{
    public function resolve(Request $request): ?ConsentSnapshot;
}
