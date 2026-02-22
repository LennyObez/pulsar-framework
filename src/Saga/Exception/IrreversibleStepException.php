<?php

declare(strict_types=1);

namespace Pulsar\Saga\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when an irreversible step is encountered during compensation.
 *
 * Irreversible steps (e.g., sending emails, external notifications) cannot
 * be compensated. The saga skips compensation for these steps and logs
 * the occurrence for operator action.
 */
#[Api(since: '1.0.0')]
final class IrreversibleStepException extends SagaException
{
    #[NoDiscard]
    public static function cannotCompensate(string $sagaId, string $stepName): self
    {
        return new self(sprintf(
            'Cannot compensate irreversible step "%s" in saga "%s"',
            $stepName,
            $sagaId,
        ));
    }
}
