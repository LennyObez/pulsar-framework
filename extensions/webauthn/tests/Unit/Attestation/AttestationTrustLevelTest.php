<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Attestation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Attestation\AttestationTrustLevel;

#[CoversClass(AttestationTrustLevel::class)]
final class AttestationTrustLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, AttestationTrustLevel}>
     */
    public static function trustLevelProvider(): iterable
    {
        yield 'none' => ['none', AttestationTrustLevel::None];
        yield 'self' => ['self', AttestationTrustLevel::Self];
        yield 'basic' => ['basic', AttestationTrustLevel::Basic];
        yield 'attestation_ca' => ['attestation_ca', AttestationTrustLevel::AttestationCa];
    }

    #[Test]
    #[DataProvider('trustLevelProvider')]
    public function fromValueReturnsCorrectCase(string $value, AttestationTrustLevel $expected): void
    {
        self::assertSame($expected, AttestationTrustLevel::from($value));
    }

    #[Test]
    public function casesReturnsFourEntries(): void
    {
        self::assertCount(4, AttestationTrustLevel::cases());
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(AttestationTrustLevel::tryFrom('unknown'));
    }
}
