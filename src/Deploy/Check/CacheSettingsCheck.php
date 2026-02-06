<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

/**
 * Validates that the framework cache is warm in production.
 *
 * A warm cache (config, routes, container) eliminates file parsing and
 * reflection on every request, significantly improving response times.
 */
#[Internal]
final readonly class CacheSettingsCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'cache-settings';

    public function __construct(
        private FrameworkCache $frameworkCache,
    ) {}

    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    public function getDescription(): string
    {
        return 'Validates the framework cache is warm for production performance';
    }

    public function check(string $environment): CheckResult
    {
        $isWarm = $this->frameworkCache->isWarm();

        if ($isWarm) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Framework cache is warm',
            );
        }

        return match ($environment) {
            'production' => CheckResult::warning(
                self::CHECK_NAME,
                'Framework cache is not warm',
                [
                    'Run "php bin/pulsar optimize" to warm the config, route, and container caches.',
                    'A cold cache causes file parsing and reflection on every request.',
                ],
            ),
            'staging' => CheckResult::warning(
                self::CHECK_NAME,
                'Framework cache is not warm',
                [
                    'Run "php bin/pulsar optimize" to warm caches and validate cacheability.',
                ],
            ),
            default => CheckResult::pass(
                self::CHECK_NAME,
                'Cache warming not required in local environment',
            ),
        };
    }
}
