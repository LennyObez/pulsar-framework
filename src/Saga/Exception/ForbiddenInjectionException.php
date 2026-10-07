<?php

declare(strict_types=1);

namespace Pulsar\Saga\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when a saga step handler attempts to resolve a forbidden service.
 *
 * In regulated presets, saga step handlers must use OutboxPort for integration
 * events. This exception is thrown by the runtime container guard when a
 * handler attempts to resolve IntegrationEventBusPort directly.
 * @api
 */
#[Api(since: '1.0.0')]
final class ForbiddenInjectionException extends SagaException
{
    #[NoDiscard]
    public static function integrationEventBusInSagaStep(string $handlerClass): self
    {
        return new self(sprintf(
            'Saga step handler "%s" attempted to resolve IntegrationEventBusPort. '
            . 'Use OutboxPort instead for atomic event emission in saga steps.',
            $handlerClass,
        ));
    }
}
