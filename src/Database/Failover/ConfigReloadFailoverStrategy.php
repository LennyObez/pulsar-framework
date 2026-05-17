<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Closure;
use Override;
use Pulsar\Api\Api;

/**
 * Resolves a failover target by reloading configuration.
 *
 * The provided callable returns an updated configuration array.
 * The strategy extracts the 'host' key as the new primary endpoint.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConfigReloadFailoverStrategy implements FailoverStrategyInterface
{
    /**
     * @param Closure(): array<string, mixed> $configLoader
     */
    public function __construct(
        private Closure $configLoader,
    ) {}

    #[Override]
    public function resolveTarget(): ?string
    {
        $config = ($this->configLoader)();

        /** @var string|null $host */
        $host = $config['host'] ?? null;

        return $host !== '' ? $host : null;
    }

    #[Override]
    public function name(): string
    {
        return 'config-reload';
    }
}
