<?php

declare(strict_types=1);

namespace Pulsar\Queue\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for queue system errors.
 */
#[Api(since: '1.0.0')]
final class QueueException extends RuntimeException
{
    /**
     * The requested driver is not configured or unavailable.
     */
    #[NoDiscard]
    public static function driverNotConfigured(string $driver): self
    {
        return new self(sprintf('Queue driver "%s" is not configured', $driver));
    }

    /**
     * A job with the given identifier could not be found.
     */
    #[NoDiscard]
    public static function jobNotFound(string $id): self
    {
        return new self(sprintf('Queue job not found: "%s"', $id));
    }

    /**
     * A job failed during execution.
     */
    #[NoDiscard]
    public static function jobFailed(string $id, string $reason): self
    {
        return new self(sprintf('Queue job "%s" failed: %s', $id, $reason));
    }

    /**
     * A job exceeded its maximum allowed attempts.
     */
    #[NoDiscard]
    public static function maxAttemptsExceeded(string $id, int $attempts): self
    {
        return new self(sprintf(
            'Queue job "%s" exceeded maximum attempts (%d)',
            $id,
            $attempts,
        ));
    }

    /**
     * A job class could not be serialized or deserialized.
     */
    #[NoDiscard]
    public static function serializationFailed(string $jobClass): self
    {
        return new self(sprintf('Failed to serialize/deserialize job class "%s"', $jobClass));
    }

    /**
     * A job class is not in the type allowlist.
     */
    #[NoDiscard]
    public static function typeNotAllowed(string $class): self
    {
        return new self(sprintf('Job class "%s" is not registered in the type allowlist', $class));
    }

    /**
     * An envelope is missing a required field.
     */
    #[NoDiscard]
    public static function missingEnvelopeField(string $field): self
    {
        return new self(sprintf('Job envelope is missing required field "%s"', $field));
    }

    /**
     * An envelope contained invalid or malformed data.
     */
    #[NoDiscard]
    public static function invalidEnvelope(string $reason): self
    {
        return new self(sprintf('Invalid job envelope: %s', $reason));
    }

    /**
     * A schema version is not compatible with the current version.
     */
    #[NoDiscard]
    public static function incompatibleSchemaVersion(string $class, int $version, int $current): self
    {
        return new self(sprintf(
            'Schema version %d for job class "%s" is not compatible with current version %d',
            $version,
            $class,
            $current,
        ));
    }

    /**
     * A job class is missing a required effect classification attribute.
     *
     * Every job must declare exactly one of: #[Idempotent], #[SideEffectFree], or #[NonIdempotent].
     */
    #[NoDiscard]
    public static function missingEffectClassification(string $jobClass): self
    {
        return new self(sprintf(
            'Job class "%s" is missing a required effect classification attribute '
            . '(#[Idempotent], #[SideEffectFree], or #[NonIdempotent])',
            $jobClass,
        ));
    }

    /**
     * A non-idempotent job was dispatched without an explicit allowance in a regulated preset.
     */
    #[NoDiscard]
    public static function nonIdempotentNotAllowed(string $jobClass): self
    {
        return new self(sprintf(
            'Job class "%s" is marked #[NonIdempotent] but does not have '
            . '#[AllowNonIdempotent] — required in regulated presets',
            $jobClass,
        ));
    }

    /**
     * A job dispatched in a regulated preset is missing a subject ID.
     */
    #[NoDiscard]
    public static function missingSubjectId(string $jobClass): self
    {
        return new self(sprintf(
            'Job class "%s" requires a subject ID in regulated presets '
            . '(add #[SystemJob] to exempt system-level jobs)',
            $jobClass,
        ));
    }

    /**
     * A DLQ delete was attempted without a reason in regulated mode.
     */
    #[NoDiscard]
    public static function deleteReasonRequired(string $failedJobId): self
    {
        return new self(sprintf(
            'Deleting DLQ job "%s" requires an explicit reason in regulated mode',
            $failedJobId,
        ));
    }

    /**
     * A job was rejected because the queue rate limit was exceeded.
     */
    #[NoDiscard]
    public static function rateLimitExceeded(string $queue, string $jobClass): self
    {
        return new self(sprintf(
            'Rate limit exceeded for queue "%s" while processing job class "%s"',
            $queue,
            $jobClass,
        ));
    }

    /**
     * A duplicate job was rejected based on its idempotency key.
     */
    #[NoDiscard]
    public static function duplicateJob(string $id, string $idempotencyKey): self
    {
        return new self(sprintf(
            'Duplicate job "%s" rejected — idempotency key "%s" is already in use',
            $id,
            $idempotencyKey,
        ));
    }
}
