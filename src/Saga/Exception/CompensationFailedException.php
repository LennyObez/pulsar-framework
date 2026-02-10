<?php

declare(strict_types=1);

namespace Pulsar\Saga\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function sprintf;

/**
 * Thrown when a compensation action itself fails.
 *
 * This is a critical error — the saga is in a partially compensated state
 * and requires operator intervention.
 */
#[Api(since: '1.0.0')]
final class CompensationFailedException extends SagaException
{
    #[NoDiscard]
    public static function forStep(
        string $sagaId,
        string $stepName,
        Throwable $cause,
    ): self {
        return new self(
            sprintf(
                'Compensation failed for saga "%s" step "%s": %s',
                $sagaId,
                $stepName,
                $cause->getMessage(),
            ),
            previous: $cause,
        );
    }
}
