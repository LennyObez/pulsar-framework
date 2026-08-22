<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\FipsValidationResult;
use Pulsar\Security\Crypto\FipsValidator;

#[CoversClass(FipsValidator::class)]
#[CoversClass(FipsValidationResult::class)]
final class FipsValidatorTest extends TestCase
{
    #[Test]
    public function verifyReturnsStructuredResult(): void
    {
        $result = FipsValidator::verify();

        self::assertInstanceOf(FipsValidationResult::class, $result);
        // Verify all fields are populated
        self::assertNotEmpty($result->opensslVersion);
        // AES-256-GCM should always be available in modern PHP
        self::assertTrue($result->aes256GcmAvailable);
        self::assertTrue($result->hmacSha256Available);
    }

    #[Test]
    public function aes256GcmIsAvailable(): void
    {
        // AES-256-GCM should be available in any modern OpenSSL build
        self::assertTrue(FipsValidator::isAes256GcmAvailable());
    }

    #[Test]
    public function hmacSha256IsAvailable(): void
    {
        // HMAC-SHA-256 should always be available
        self::assertTrue(FipsValidator::isHmacSha256Available());
    }

    #[Test]
    public function isFipsAvailableReturnsBool(): void
    {
        // We can't control whether FIPS is enabled in the test environment,
        // but we can verify the method returns a boolean without throwing.
        $isFips = FipsValidator::isFipsAvailable();
        $fullResult = FipsValidator::verify();

        // isFipsAvailable should match the verify result
        self::assertSame($fullResult->fipsDetected, $isFips);
    }

    #[Test]
    public function verifyResultHasWarningsWhenNotFips(): void
    {
        $result = FipsValidator::verify();

        // In a standard (non-FIPS) test environment, FIPS is not detected
        if (!$result->fipsDetected) {
            self::assertNotEmpty($result->warnings);
            self::assertFalse($result->compliant);
        } else {
            // In a FIPS environment, no warnings should appear
            self::assertEmpty($result->warnings);
            self::assertTrue($result->compliant);
        }
    }

    #[Test]
    public function summaryDescribesComplianceState(): void
    {
        $result = FipsValidator::verify();
        $summary = $result->summary();

        self::assertNotEmpty($summary);

        if ($result->compliant) {
            self::assertStringContainsString('compliant', $summary);
        } else {
            self::assertStringContainsString('not compliant', $summary);
        }
    }

    #[Test]
    public function resultConstructorSetsAllFields(): void
    {
        $result = new FipsValidationResult(
            compliant: true,
            fipsDetected: true,
            aes256GcmAvailable: true,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.0 FIPS',
            warnings: [],
        );

        self::assertTrue($result->compliant);
        self::assertTrue($result->fipsDetected);
        self::assertTrue($result->aes256GcmAvailable);
        self::assertTrue($result->hmacSha256Available);
        self::assertSame('OpenSSL 3.0.0 FIPS', $result->opensslVersion);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function nonCompliantResultIncludesWarningsInSummary(): void
    {
        $result = new FipsValidationResult(
            compliant: false,
            fipsDetected: false,
            aes256GcmAvailable: true,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.0',
            warnings: ['FIPS mode not detected'],
        );

        $summary = $result->summary();

        self::assertStringContainsString('not compliant', $summary);
        self::assertStringContainsString('FIPS mode not detected', $summary);
    }

    #[Test]
    public function compliantResultHasCleanSummary(): void
    {
        $result = new FipsValidationResult(
            compliant: true,
            fipsDetected: true,
            aes256GcmAvailable: true,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.0 FIPS',
            warnings: [],
        );

        self::assertSame('FIPS 140-2 compliant: all requirements met', $result->summary());
    }
}
