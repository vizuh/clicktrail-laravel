<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Support;

/**
 * Render-only snippet builders shared by Blade directives and view components.
 *
 * - NEVER makes HTTP calls, queries anything, or persists anything.
 * - NEVER evaluates consent legality; renders whatever snapshot values are
 *   passed in.
 * - All dynamic output is escaped with htmlspecialchars(..., ENT_QUOTES).
 */
final class SnippetRenderer
{
    /** Normalized consent signals of the ClickTrailConsentSnapshot contract. */
    private const CONSENT_SIGNALS = [
        'functional_storage',
        'analytics_storage',
        'advertising_storage',
        'ad_user_data',
        'ad_personalization',
    ];

    /**
     * First-party loader script tag plus data-ct-* config attributes.
     * Key "script_src" becomes the src attribute; every other scalar key
     * becomes data-ct-<key> (underscores -> hyphens).
     *
     * @param array<string, mixed> $config full clicktrail config array
     */
    public static function head(array $config): string
    {
        $src = $config['script_src'] ?? '';
        if (! is_string($src) || $src === '') {
            return '';
        }

        $html = '<script src="' . self::esc($src) . '"';
        foreach ($config as $key => $value) {
            if ($key === 'script_src' || ! is_scalar($value)) {
                continue;
            }
            if (in_array($key, ['api_key', 'consent_resolver', 'queue', 'queue_connection', 'endpoint'], true)) {
                continue; // server-side only keys must never leak into markup
            }
            $attr = 'data-ct-' . str_replace('_', '-', (string) $key);
            $html .= ' ' . self::esc($attr) . '="' . self::esc((string) $value) . '"';
        }

        return $html . ' async></script>';
    }

    /**
     * Normalized consent snapshot as data-ct-consent-* attributes for use
     * inside an opening tag. Unknown/missing keys render nothing.
     *
     * @param array<string, mixed> $snapshot ConsentSnapshot::toArray() shape
     * @param string[] $signals override the rendered signal list
     */
    public static function consentState(array $snapshot, array $signals = self::CONSENT_SIGNALS): string
    {
        $html = '';
        foreach ($signals as $signal) {
            if (! isset($snapshot[$signal]) || ! is_scalar($snapshot[$signal])) {
                continue;
            }
            $value = (string) $snapshot[$signal];
            if ($value === '') {
                continue;
            }
            $html .= ' data-ct-consent-' . str_replace('_', '-', $signal)
                . '="' . self::esc($value) . '"';
        }

        return $html;
    }

    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
