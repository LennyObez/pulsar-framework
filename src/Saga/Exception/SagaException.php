<?php

declare(strict_types=1);

namespace Pulsar\Saga\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for saga orchestration errors.
 */
#[Api(since: '1.0.0')]
class SagaException extends RuntimeException
{
    #[NoDiscard]
    public static function stepFailed(string $sagaId, string $stepName, string $reason): self
    {
        return new self(sprintf(
            'Saga "%s" step "%s" failed: %s',
            $sagaId,
            $stepName,
            $reason,
        ));
    }

    #[NoDiscard]
    public static function sagaNotFound(string $sagaId): self
    {
        return new self(sprintf('Saga "%s" not found', $sagaId));
    }

    #[NoDiscard]
    public static function invalidState(string $sagaId, string $expectedStatus, string $actualStatus): self
    {
        return new self(sprintf(
            'Saga "%s" is in status "%s", expected "%s"',
            $sagaId,
            $actualStatus,
            $expectedStatus,
        ));
    }

    #[NoDiscard]
    public static function definitionNotFound(string $definitionId): self
    {
        return new self(sprintf('Saga definition "%s" not found', $definitionId));
    }

    #[NoDiscard]
    public static function noStepsDefined(string $definitionId): self
    {
        return new self(sprintf('Saga definition "%s" has no steps defined', $definitionId));
    }
}
