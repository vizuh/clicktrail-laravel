<?php

declare(strict_types=1);

namespace ClickTrail\Laravel\Console;

use ClickTrail\Laravel\Consent\ConsentResolverInterface;
use ClickTrail\Laravel\Consent\NullConsentResolver;
use Illuminate\Console\Command;

final class ClickTrailDiagnoseCommand extends Command
{
    protected $signature = 'clicktrail:diagnose';

    protected $description = 'Check ClickTrail configuration, endpoint reachability and consent resolver wiring';

    public function handle(): int
    {
        $ok = true;

        foreach (['site_id', 'endpoint'] as $key) {
            /** @var mixed $value */
            $value = config("clicktrail.{$key}");
            $present = is_string($value) && $value !== '';
            $this->{$present ? 'info' : 'error'}(sprintf(
                '%s %s: %s',
                $present ? '[ok]' : '[missing]',
                "clicktrail.{$key}",
                $present ? (str_contains((string) $value, '://') ? (string) $value : '<set>') : 'not configured',
            ));
            $ok = $ok && $present;
        }

        // Endpoint reachability flag: coarse TCP connect to host:port.
        // TODO verify: TCP connect is a proxy for HTTP reachability; swap for a
        // real OPTIONS/HEAD probe against events/batch when live verification lands.
        /** @var string $endpoint */
        $endpoint = (string) config('clicktrail.endpoint', '');
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT) ?? (parse_url($endpoint, PHP_URL_SCHEME) === 'http' ? 80 : 443);
        if (! is_string($host) || $host === '') {
            $this->error('[missing] endpoint URL does not parse');
            $reachable = false;
        } else {
            $fp = @fsockopen($host, (int) $port, $errno, $errstr, 2.0);
            $reachable = is_resource($fp);
            if ($fp !== false) {
                fclose($fp);
            }
            $detail = 'unreachable';
            if ($reachable) {
                $detail = 'reachable';
            } elseif (trim((string) $errstr) !== '') {
                $detail = sprintf('unreachable (%s)', $errstr);
            }
            $this->{$reachable ? 'info' : 'warn'}(sprintf(
                '%s endpoint reachability (tcp %s:%d): %s',
                $reachable ? '[ok]' : '[warn]',
                $host,
                (int) $port,
                $detail,
            ));
            // Reachability is advisory only; it must not fail the command.
        }

        $resolverClass = config('clicktrail.consent_resolver');
        if (! is_string($resolverClass) || $resolverClass === '') {
            $this->warn(sprintf(
                '[warn] consent resolver: none configured - %s active (unknown consent = denied, nothing persists)',
                NullConsentResolver::class,
            ));
        } elseif (class_exists($resolverClass) && is_subclass_of($resolverClass, ConsentResolverInterface::class)) {
            $this->info(sprintf('[ok] consent resolver: %s resolves', $resolverClass));
        } else {
            $this->error(sprintf(
                '[missing] consent resolver: "%s" does not exist or does not implement %s - falling back to %s at runtime',
                $resolverClass,
                ConsentResolverInterface::class,
                NullConsentResolver::class,
            ));
            $ok = false;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
