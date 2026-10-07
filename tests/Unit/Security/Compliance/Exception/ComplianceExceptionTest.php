<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Exception\ComplianceException;
use RuntimeException;

#[CoversClass(ComplianceException::class)]
final class ComplianceExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new ComplianceException('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function missingClassificationIncludesFieldName(): void
    {
        $exception = ComplianceException::missingClassification('email');

        self::assertStringContainsString('email', $exception->getMessage());
        self::assertStringContainsString('classification', $exception->getMessage());
    }

    #[Test]
    public function invalidSchemaVersionIncludesBothVersions(): void
    {
        $exception = ComplianceException::invalidSchemaVersion(3, 1);

        self::assertStringContainsString('3', $exception->getMessage());
        self::assertStringContainsString('1', $exception->getMessage());
    }

    #[Test]
    public function snapshotCaptureRefusedIncludesReason(): void
    {
        $exception = ComplianceException::snapshotCaptureRefused('insufficient permissions');

        self::assertStringContainsString('insufficient permissions', $exception->getMessage());
        self::assertStringContainsString('refused', $exception->getMessage());
    }

    #[Test]
    public function pseudonymNotFoundHasFixedMessage(): void
    {
        $exception = ComplianceException::pseudonymNotFound();

        self::assertStringContainsString('Pseudonym not found', $exception->getMessage());
    }

    #[Test]
    public function retentionPolicyViolationIncludesReason(): void
    {
        $exception = ComplianceException::retentionPolicyViolation('data older than 365 days');

        self::assertStringContainsString('data older than 365 days', $exception->getMessage());
        self::assertStringContainsString('Retention policy', $exception->getMessage());
    }

    #[Test]
    public function evidenceExportFailedIncludesReason(): void
    {
        $exception = ComplianceException::evidenceExportFailed('disk full');

        self::assertStringContainsString('disk full', $exception->getMessage());
        self::assertStringContainsString('Evidence export', $exception->getMessage());
    }
}
