<?php

declare(strict_types=1);

namespace Pulsar\Container\Scope;

use Exception;
use NoDiscard;
use Psr\Container\ContainerExceptionInterface;
use Pulsar\Api\Api;
use Pulsar\Container\Lifetime;

use function sprintf;

/**
 * Thrown when a longer-lived service depends on a shorter-lived service.
 *
 * For example, a singleton depending on a request-scoped service would
 * hold a stale reference after the request scope ends.
 * @api
 */
#[Api(since: '1.0.0')]
final class ScopeWideningException extends Exception implements ContainerExceptionInterface
{
    /**
     * Create an exception for a detected scope widening violation.
     */
    #[NoDiscard]
    public static function detected(
        string $singletonId,
        Lifetime $singletonLifetime,
        string $depId,
        Lifetime $depLifetime,
    ): self {
        return new self(sprintf(
            'Scope widening: service "%s" (%s) depends on "%s" (%s). '
            . 'A longer-lived service must not depend on a shorter-lived one.',
            $singletonId,
            $singletonLifetime->value,
            $depId,
            $depLifetime->value,
        ));
    }
}
