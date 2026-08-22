<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;

/**
 * Validates that the framework cache is warm in production.
 *
 * In production, a cold cache means routes, config, and container hints
 * are rebuilt on every boot: a significant performance penalty.
 */
#[Internal]
final readonly class FilesystemScanCheck implements DeployCheckInterface
{
    public function __construct(
        private FrameworkCacheInterface $frameworkCache,
    ) {}

    public function getName(): string
    {
        return 'filesystem-scan';
    }

    public function getDescription(): string
    {
        return 'Validates framework cache is warm to avoid runtime filesystem scanning';
    }

    public function check(string $environment): CheckResult
    {
        if ($environment === 'local') {
            return CheckResult::pass(
                $this->getName(),
                'Filesystem scanning is acceptable in local development.',
            );
        }

        if ($this->frameworkCache->isWarm()) {
            return CheckResult::pass(
                $this->getName(),
                'Framework cache is warm: no runtime filesystem scanning required.',
            );
        }

        $severity = $environment === 'production' ? 'error' : 'warning';

        return $severity === 'error'
            ? CheckResult::error(
                $this->getName(),
                'Framework cache is cold: config, routes, and container will be scanned on every boot.',
                ['Run `php bin/pulsar optimize` to warm the framework cache.'],
            )
            : CheckResult::warning(
                $this->getName(),
                'Framework cache is cold: consider warming it for staging.',
                ['Run `php bin/pulsar optimize` to warm the framework cache.'],
            );
    }
}
