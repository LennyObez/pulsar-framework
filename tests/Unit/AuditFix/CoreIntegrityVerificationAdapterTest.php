<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreIntegrityVerificationAdapter;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Integrity\VerificationResult;
use RuntimeException;

/**
 * Verifies that the CoreIntegrityVerificationAdapter correctly delegates
 * to the core ManifestVerifier and handles error scenarios gracefully.
 */
#[CoversClass(CoreIntegrityVerificationAdapter::class)]
final class CoreIntegrityVerificationAdapterTest extends TestCase
{
    #[Test]
    public function runReturnsPassingResultWhenNoManifest(): void
    {
        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $adapter = new CoreIntegrityVerificationAdapter($verifier, null);

        $result = $adapter->run();

        self::assertTrue($result->passed);
        self::assertSame(0, $result->verified);
        self::assertSame(0, $result->modified);
    }

    #[Test]
    public function runDelegatesToCoreVerifier(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 0,
            entries: [],
        );

        $expectedResult = new VerificationResult(
            passed: true,
            verified: 5,
            modified: 0,
            missing: 0,
            added: 0,
            files: [],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willReturn($expectedResult);

        $adapter = new CoreIntegrityVerificationAdapter($verifier, $manifest);
        $result = $adapter->run();

        self::assertTrue($result->passed);
        self::assertSame(5, $result->verified);
    }

    #[Test]
    public function runReturnsPassingResultOnVerifierException(): void
    {
        $manifest = new IntegrityManifest(
            version: 1,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 0,
            entries: [],
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willThrowException(new RuntimeException('File not found'));

        $adapter = new CoreIntegrityVerificationAdapter($verifier, $manifest);
        $result = $adapter->run();

        self::assertTrue($result->passed);
        self::assertSame(0, $result->verified);
    }
}
