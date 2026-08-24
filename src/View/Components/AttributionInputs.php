<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\View\Components;

use ClickTrail\Laravel\ClickTrailManager;
use ClickTrail\Laravel\Support\AttributionFields;
use ClickTrail\Laravel\Support\SnippetRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\View\Component;

/**
 * <x-clicktrail::attribution-inputs /> and the @clicktrailAttribution directive:
 * hidden form inputs carrying the full attribution context. Field names are
 * ct_-prefixed; empty values are skipped; all values HTML-escaped.
 *
 * Values come from an explicitly passed map or from the session-stored
 * StoredState (built by clicktrail.capture middleware).
 */
final class AttributionInputs extends Component
{
    public const HIDDEN_FIELD_ORDER = AttributionFields::HIDDEN_FIELD_ORDER;

    /**
     * @param array<string, string>|null $attribution precomputed flat map; null derives from session state
     * @param string $idPrefix prefix for input names (canonical: ct_)
     */
    public function __construct(
        ?array $attribution = null,
        public string $idPrefix = 'ct_',
    ) {
        if ($attribution !== null) {
            $this->fields = $attribution;
        } else {
            $this->fields = self::fromSession();
        }
    }

    /** @var array<string, string> */
    public array $fields = [];

    public function renderInputs(): string
    {
        $html = '';
        foreach (AttributionFields::HIDDEN_FIELD_ORDER as $field) {
            if (! isset($this->fields[$field]) || $this->fields[$field] === '') {
                continue;
            }
            $html .= '<input type="hidden" name="' . SnippetRenderer::esc($this->idPrefix . $field)
                . '" value="' . SnippetRenderer::esc((string) $this->fields[$field]) . '">' . "\n";
        }

        return $html;
    }

    public function render(): View
    {
        return view('clicktrail::components.attribution-inputs', [
            'inputs' => $this->renderInputs(),
        ]);
    }

    /** @return array<string, string> */
    private static function fromSession(): array
    {
        $request = request();
        if (! $request instanceof Request || ! $request->hasSession()) {
            return [];
        }

        /** @var ClickTrailManager $manager */
        $manager = app(ClickTrailManager::class);
        /** @var string $key */
        $key = config('clicktrail.session_key', 'clicktrail.attribution');
        /** @var mixed $json */
        $json = $request->session()->get($key);

        $state = \ClickTrail\Core\StoredState::fromJson(is_string($json) ? $json : null);
        $consent = $state->first !== null || $state->last !== null
            ? $manager->consentResolver()->resolve($request)
            : null;

        /** @var mixed $siteId */
        $siteId = config('clicktrail.site_id');

        return AttributionFields::flatten($state, $consent, [
            'site_id' => is_string($siteId) ? $siteId : '',
        ]);
    }
}
