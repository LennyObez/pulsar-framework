<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\ConsumeReason;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;
use Pulsar\Auth\TwoFactor\VerifyReason;

#[CoversNothing]
final class TwoFactorEnumTest extends TestCase
{
    // ── ConsumeReason ─────────────────────────────────────────────────

    #[Test]
    public function consumeReasonHasFiveCases(): void
    {
        self::assertCount(5, ConsumeReason::cases());
    }

    #[Test]
    #[DataProvider('consumeReasonProvider')]
    public function consumeReasonBackedValues(ConsumeReason $reason, string $expected): void
    {
        self::assertSame($expected, $reason->value);
    }

    /**
     * @return iterable<string, array{ConsumeReason, string}>
     */
    public static function consumeReasonProvider(): iterable
    {
        yield 'Consumed' => [ConsumeReason::Consumed, 'consumed'];
        yield 'NotFound' => [ConsumeReason::NotFound, 'not_found'];
        yield 'AlreadyUsed' => [ConsumeReason::AlreadyUsed, 'already_used'];
        yield 'NotEnrolled' => [ConsumeReason::NotEnrolled, 'not_enrolled'];
        yield 'RateLimited' => [ConsumeReason::RateLimited, 'rate_limited'];
    }

    // ── VerifyReason ──────────────────────────────────────────────────

    #[Test]
    public function verifyReasonHasSixCases(): void
    {
        self::assertCount(6, VerifyReason::cases());
    }

    #[Test]
    #[DataProvider('verifyReasonProvider')]
    public function verifyReasonBackedValues(VerifyReason $reason, string $expected): void
    {
        self::assertSame($expected, $reason->value);
    }

    /**
     * @return iterable<string, array{VerifyReason, string}>
     */
    public static function verifyReasonProvider(): iterable
    {
        yield 'Valid' => [VerifyReason::Valid, 'valid'];
        yield 'InvalidCode' => [VerifyReason::InvalidCode, 'invalid_code'];
        yield 'Replayed' => [VerifyReason::Replayed, 'replayed'];
        yield 'Expired' => [VerifyReason::Expired, 'expired'];
        yield 'NotEnrolled' => [VerifyReason::NotEnrolled, 'not_enrolled'];
        yield 'RateLimited' => [VerifyReason::RateLimited, 'rate_limited'];
    }

    // ── TwoFactorPurpose ──────────────────────────────────────────────

    #[Test]
    public function twoFactorPurposeHasThreeCases(): void
    {
        self::assertCount(3, TwoFactorPurpose::cases());
    }

    #[Test]
    #[DataProvider('twoFactorPurposeProvider')]
    public function twoFactorPurposeBackedValues(TwoFactorPurpose $purpose, string $expected): void
    {
        self::assertSame($expected, $purpose->value);
    }

    /**
     * @return iterable<string, array{TwoFactorPurpose, string}>
     */
    public static function twoFactorPurposeProvider(): iterable
    {
        yield 'Login' => [TwoFactorPurpose::Login, 'login'];
        yield 'Setup' => [TwoFactorPurpose::Setup, 'setup'];
        yield 'StepUp' => [TwoFactorPurpose::StepUp, 'step_up'];
    }

    #[Test]
    public function fromBackedValues(): void
    {
        self::assertSame(ConsumeReason::Consumed, ConsumeReason::from('consumed'));
        self::assertSame(VerifyReason::Replayed, VerifyReason::from('replayed'));
        self::assertSame(TwoFactorPurpose::StepUp, TwoFactorPurpose::from('step_up'));
    }
}
