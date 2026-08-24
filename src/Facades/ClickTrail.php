<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ClickTrail\Core\StoredState capture(\Illuminate\Http\Request $request)
 * @method static \ClickTrail\Laravel\Consent\ConsentResolverInterface consentResolver()
 * @method static \ClickTrail\Client\BatchClient client()
 *
 * @see \ClickTrail\Laravel\ClickTrailManager
 */
final class ClickTrail extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'clicktrail';
    }
}
