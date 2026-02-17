<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\FipsValidationResult;

#[CoversClass(FipsValidationResult::class)]
final class FipsValidationResultTest extends TestCase
{
    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $result = new FipsValidationResult(
            compliant: true,
            fipsDetected: true,
            aes256GcmAvailable: true,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.12 FIPS',
            warnings: [],
        );

        self::assertTrue($result->compliant);
        self::assertTrue($result->fipsDetected);
        self::assertTrue($result->aes256GcmAvailable);
        self::assertTrue($result->hmacSha256Available);
        self::assertSame('OpenSSL 3.0.12 FIPS', $result->opensslVersion);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function summaryReturnsCompliantMessage(): void
    {
        $result = new FipsValidationResult(
            compliant: true,
            fipsDetected: true,
            aes256GcmAvailable: true,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.12 FIPS',
            warnings: [],
        );

        self::assertSame('FIPS 140-2 compliant: all requirements met', $result->summary());
    }

    #[Test]
    public function summaryReturnsNonCompliantMessageWithWarnings(): void
    {
        $result = new FipsValidationResult(
            compliant: false,
            fipsDetected: false,
            aes256GcmAvailable: true,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.12',
            warnings: ['FIPS mode not detected', 'Non-FIPS OpenSSL build'],
        );

        self::assertSame(
            'FIPS 140-2 not compliant: FIPS mode not detected; Non-FIPS OpenSSL build',
            $result->summary(),
        );
    }

    #[Test]
    public function nonCompliantWithSingleWarning(): void
    {
        $result = new FipsValidationResult(
            compliant: false,
            fipsDetected: true,
            aes256GcmAvailable: false,
            hmacSha256Available: true,
            opensslVersion: 'OpenSSL 3.0.12 FIPS',
            warnings: ['AES-256-GCM cipher not available'],
        );

        self::assertStringContainsString('AES-256-GCM cipher not available', $result->summary());
    }
}
