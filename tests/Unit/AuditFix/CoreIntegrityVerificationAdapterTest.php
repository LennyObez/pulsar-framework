<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreIntegrityVerificationAdapter;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestScope;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Integrity\VerificationResult;
use RuntimeException;

/**
 * What the integrity dashboard is told when verification did not happen.
 *
 * Both no-manifest and verifier-threw used to return a passing result, so the
 * status page showed green having never hashed a file. A control that reports
 * "intact" for "not checked" is worse than no control: it answers the question
 * an operator actually asked, with a value it did not measure.
 */
#[CoversClass(CoreIntegrityVerificationAdapter::class)]
final class CoreIntegrityVerificationAdapterTest extends TestCase
{
    #[Test]
    public function runReportsFailureWhenThereIsNoManifest(): void
    {
        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $adapter = new CoreIntegrityVerificationAdapter($verifier, null);

        $result = $adapter->run();

        self::assertFalse($result->passed);
        self::assertSame(0, $result->verified);
        self::assertSame(0, $result->modified);
    }

    #[Test]
    public function runDelegatesToCoreVerifier(): void
    {
        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 0,
            entries: [],
            scope: new ManifestScope(['src/**/*.php'], []),
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

    /**
     * A manifest that cannot be verified — unreadable file, undefined scope —
     * is a reason to raise the alarm, not to suppress it.
     */
    #[Test]
    public function runReportsFailureWhenTheVerifierThrows(): void
    {
        $manifest = new IntegrityManifest(
            version: IntegrityManifest::VERSION,
            algorithm: 'sha256',
            generatedAt: 1700000000,
            frameworkVersion: '1.0.0',
            entryCount: 0,
            entries: [],
            scope: new ManifestScope(['src/**/*.php'], []),
        );

        $verifier = $this->createStub(ManifestVerifierInterface::class);
        $verifier->method('verify')->willThrowException(new RuntimeException('File not found'));

        $adapter = new CoreIntegrityVerificationAdapter($verifier, $manifest);
        $result = $adapter->run();

        self::assertFalse($result->passed);
        self::assertSame(0, $result->verified);
    }
}
