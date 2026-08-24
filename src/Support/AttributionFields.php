<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Support;

use ClickTrail\Consent\ConsentSnapshot;
use ClickTrail\Core\StoredState;
use ClickTrail\Core\Touch;

/**
 * Canonical hidden-attribution field contract and StoredState flattening,
 * mirroring the October AttributionHidden component / twig extension /
 * GTM attribution variables. Single source of truth for field order here.
 */
final class AttributionFields
{
    /** Canonical hidden-input field order. Keep in parity with clicktrail-twig + October. */
    public const HIDDEN_FIELD_ORDER = [
        'visitor_id',
        'session_id',
        'event_id',
        'site_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'utm_id',
        // All 10 ad click IDs (Stable::CLICK_ID_KEYS parity with php-sdk).
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclkid',
        'ttclid',
        'twclid',
        'li_fat_id',
        'sccid',
        'epik',
        'landing_page',
        'initial_referrer',
        'consent_state',
    ];

    // UTM keys as stored on Touch (property name => flat field name).
    private const TOUCH_UTMS = [
        'source' => 'utm_source',
        'medium' => 'utm_medium',
        'campaign' => 'utm_campaign',
        'content' => 'utm_content',
        'term' => 'utm_term',
        'utmId' => 'utm_id',
    ];

    /**
     * Flatten stored first/last-touch state into the canonical flat map.
     * Values come from the LAST touch when present (most recent attribution),
     * falling back to first touch. Platform identifiers (visitor/session/
     * event/site ids) are merged from $ids. consent_state summarizes the
     * normalized snapshot ("unknown" when null).
     *
     * TODO verify: confirm last-touch precedence against October component
     * fixtures before marketplace submission.
     *
     * @param array<string, string|null> $ids visitor_id, session_id, event_id, site_id
     * @return array<string, string> only fields with non-empty values, in HIDDEN_FIELD_ORDER
     */
    public static function flatten(StoredState $state, ?ConsentSnapshot $consent = null, array $ids = []): array
    {
        $touch = $state->last ?? $state->first;
        $values = [];

        foreach ($ids as $k => $v) {
            if (is_string($v) && $v !== '') {
                $values[(string) $k] = $v;
            }
        }

        if ($touch !== null) {
            foreach (self::TOUCH_UTMS as $prop => $field) {
                $val = $touch->{$prop};
                if (is_string($val) && $val !== '') {
                    $values[$field] = $val;
                }
            }
            foreach ($touch->clickIds as $key => $cid) {
                if (is_string($cid) && $cid !== '') {
                    $values[$key] = $cid;
                }
            }
            if (is_string($touch->landingPage) && $touch->landingPage !== '') {
                $values['landing_page'] = $touch->landingPage;
            }
            if (is_string($touch->referrer) && $touch->referrer !== '') {
                $values['initial_referrer'] = $touch->referrer;
            }
        }

        $values['consent_state'] = self::summarizeConsent($consent);

        // Emit strictly in HIDDEN_FIELD_ORDER, dropping empties.
        $out = [];
        foreach (self::HIDDEN_FIELD_ORDER as $field) {
            if (isset($values[$field]) && $values[$field] !== '') {
                $out[$field] = $values[$field];
            }
        }

        return $out;
    }

    /** Compact one-string consent summary for the consent_state hidden field. */
    private static function summarizeConsent(?ConsentSnapshot $consent): string
    {
        if ($consent === null) {
            return 'unknown';
        }

        return sprintf(
            '%s:%s:%s:%s:%s:%s',
            $consent->functionalStorage->value,
            $consent->analyticsStorage->value,
            $consent->advertisingStorage->value,
            $consent->adUserData->value,
            $consent->adPersonalization->value,
            $consent->source,
        );
    }
}
