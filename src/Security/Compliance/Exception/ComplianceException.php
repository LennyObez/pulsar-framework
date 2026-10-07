<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

/**
 * Exception for compliance-related failures.
 * @api
 */
#[Api(since: '1.0.0')]
final class ComplianceException extends RuntimeException
{
    #[NoDiscard]
    public static function missingClassification(string $fieldName): self
    {
        return new self("Field '$fieldName' requires a data classification tag.");
    }

    #[NoDiscard]
    public static function invalidSchemaVersion(int $expected, int $actual): self
    {
        return new self("Expected schema version $expected, got $actual.");
    }

    #[NoDiscard]
    public static function snapshotCaptureRefused(string $reason): self
    {
        return new self("Snapshot capture refused: $reason");
    }

    #[NoDiscard]
    public static function pseudonymNotFound(): self
    {
        return new self('Pseudonym not found for the given identifier.');
    }

    #[NoDiscard]
    public static function retentionPolicyViolation(string $reason): self
    {
        return new self("Retention policy violation: $reason");
    }

    #[NoDiscard]
    public static function evidenceExportFailed(string $reason): self
    {
        return new self("Evidence export failed: $reason");
    }
}
