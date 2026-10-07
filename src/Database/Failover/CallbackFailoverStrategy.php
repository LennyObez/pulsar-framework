<?php

declare(strict_types=1);

namespace Pulsar\Database\Failover;

use Closure;
use Override;
use Pulsar\Api\Api;

/**
 * Resolves a failover target by invoking a user-provided callback.
 *
 * The callback should return the new primary endpoint as a string,
 * or null if no failover target is available.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CallbackFailoverStrategy implements FailoverStrategyInterface
{
    /**
     * @param Closure(): ?string $resolver
     */
    public function __construct(
        private Closure $resolver,
    ) {}

    #[Override]
    public function resolveTarget(): ?string
    {
        return ($this->resolver)();
    }

    #[Override]
    public function name(): string
    {
        return 'callback';
    }
}
