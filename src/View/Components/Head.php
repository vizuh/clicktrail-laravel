<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\View\Components;

use ClickTrail\Laravel\Support\SnippetRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-clicktrail::head /> - renders the first-party loader script tag plus
 * data-ct-* attributes from config. Render-only; no effects.
 */
final class Head extends Component
{
    /** @var array<string, mixed> */
    public array $config;

    /**
     * @param array<string, mixed>|null $config override; defaults to config('clicktrail')
     */
    public function __construct(?array $config = null)
    {
        /** @var mixed $resolved */
        $resolved = $config ?? config('clicktrail', []);
        $this->config = is_array($resolved) ? $resolved : [];
    }

    public function render(): View
    {
        return view('clicktrail::components.head', [
            'html' => SnippetRenderer::head($this->config),
        ]);
    }
}
