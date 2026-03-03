<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Exception;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function implode;
use function sprintf;

/**
 * Domain exception for AI governance lifecycle failures.
 *
 * Static factories expose the exact failure mode so callers can pattern-match
 * on semantic intent (model not found, deployment gate rejection, invalid
 * state transition) without string-parsing exception messages.
 *
 * Extends `InvalidArgumentException` because every failure mode below
 * represents a caller bug — referencing a model id that does not exist,
 * attempting an illegal state transition, deploying without satisfying
 * the configured gates. None of these are runtime failures of the AI
 * subsystem itself, so consumers handle them like any other PHP
 * argument-shape exception.
 */
#[Api(since: '1.0.0')]
final class AiGovernanceException extends InvalidArgumentException
{
    public static function modelNotFound(string $modelId): self
    {
        return new self(sprintf('AI model "%s" not found in registry', $modelId));
    }

    /**
     * @param list<string> $failures
     */
    public static function deploymentBlocked(string $modelId, array $failures): self
    {
        return new self(sprintf(
            'Deployment of model "%s" blocked by gate failures: %s',
            $modelId,
            implode('; ', $failures),
        ));
    }

    public static function invalidStatusTransition(
        string $modelId,
        string $fromStatus,
        string $toStatus,
    ): self {
        return new self(sprintf(
            'Invalid status transition for model "%s": %s → %s',
            $modelId,
            $fromStatus,
            $toStatus,
        ));
    }

    public static function rollbackFromNonProduction(string $modelId, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot rollback model "%s": current status is "%s", expected "production"',
            $modelId,
            $currentStatus,
        ));
    }

    public static function modelAlreadyRegistered(string $modelId): self
    {
        return new self(sprintf('AI model "%s" is already registered', $modelId));
    }
}
