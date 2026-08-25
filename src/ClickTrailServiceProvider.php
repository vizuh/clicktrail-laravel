<?php

declare(strict_types=1);

namespace ClickTrail\Laravel;

use ClickTrail\Laravel\Consent\ConsentResolverInterface;
use ClickTrail\Laravel\Consent\NullConsentResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

final class ClickTrailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->mergeConfigFrom(__DIR__ . '/../config/clicktrail.php', 'clicktrail');

        $this->app->singleton(ClickTrailManager::class, static fn (Application $app): ClickTrailManager => new ClickTrailManager($app));
        $this->app->alias(ClickTrailManager::class, 'clicktrail');

        // Consent resolver: config class-string when provided, safe
        // unknown=denied default otherwise.
        $this->app->bind(ConsentResolverInterface::class, static function (Application $app): ConsentResolverInterface {
            $class = $app->make('config')->get('clicktrail.consent_resolver');
            if (is_string($class) && $class !== '' && class_exists($class)) {
                /** @var object $instance */
                $instance = $app->make($class);
                if ($instance instanceof ConsentResolverInterface) {
                    return $instance;
                }
            }

            return new NullConsentResolver();
        });
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__ . '/../config/clicktrail.php' => config_path('clicktrail.php'),
        ], 'clicktrail-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'clicktrail');

        $router->aliasMiddleware('clicktrail.capture', Middleware\CaptureAttribution::class);

        if ((bool) config('clicktrail.first_party_proxy', false)) {
            $router->post('/clicktrail/collect', Http\Controllers\FirstPartyProxyController::class)
                ->name('clicktrail.collect');
        }

        Blade::directive('clicktrailHead', static function (): string {
            return <<<'PHP'
<?php echo \ClickTrail\Laravel\Support\SnippetRenderer::head((array) config('clicktrail')); ?>
PHP;
        });

        Blade::directive('clicktrailAttribution', static function (): string {
            return <<<'PHP'
<?php echo app(\ClickTrail\Laravel\View\Components\AttributionInputs::class)->renderInputs(); ?>
PHP;
        });

        Blade::directive('clicktrailConsent', static function (): string {
            return <<<'PHP'
<?php
$snapshot = app(\ClickTrail\Laravel\Consent\ConsentResolverInterface::class)->resolve(request());
echo \ClickTrail\Laravel\Support\SnippetRenderer::consentState($snapshot === null ? [] : $snapshot->toArray());
?>
PHP;
        });
    }
}
