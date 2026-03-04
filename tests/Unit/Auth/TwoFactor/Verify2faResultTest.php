<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\TwoFactorPurpose;
use Pulsar\Auth\TwoFactor\Verify2faResult;
use Pulsar\Auth\TwoFactor\VerifyReason;

#[CoversClass(Verify2faResult::class)]
final class Verify2faResultTest extends TestCase
{
    #[Test]
    public function successFactoryReturnsVerifiedResult(): void
    {
        $result = Verify2faResult::success(TwoFactorPurpose::Login, 42);

        self::assertTrue($result->verified);
        self::assertSame(VerifyReason::Valid, $result->reason);
        self::assertSame(TwoFactorPurpose::Login, $result->purpose);
        self::assertSame(42, $result->acceptedTimeStep);
    }

    #[Test]
    #[DataProvider('purposeProvider')]
    public function successWithAllPurposes(TwoFactorPurpose $purpose): void
    {
        $result = Verify2faResult::success($purpose, 100);

        self::assertTrue($result->verified);
        self::assertSame($purpose, $result->purpose);
        self::assertSame(100, $result->acceptedTimeStep);
    }

    /**
     * @return iterable<string, array{TwoFactorPurpose}>
     */
    public static function purposeProvider(): iterable
    {
        yield 'Login' => [TwoFactorPurpose::Login];
        yield 'Setup' => [TwoFactorPurpose::Setup];
        yield 'StepUp' => [TwoFactorPurpose::StepUp];
    }

    #[Test]
    #[DataProvider('failureReasonProvider')]
    public function failureFactoryReturnsUnverifiedResult(VerifyReason $reason): void
    {
        $result = Verify2faResult::failure($reason, TwoFactorPurpose::Login);

        self::assertFalse($result->verified);
        self::assertSame($reason, $result->reason);
        self::assertSame(TwoFactorPurpose::Login, $result->purpose);
        self::assertNull($result->acceptedTimeStep);
    }

    /**
     * @return iterable<string, array{VerifyReason}>
     */
    public static function failureReasonProvider(): iterable
    {
        yield 'InvalidCode' => [VerifyReason::InvalidCode];
        yield 'Replayed' => [VerifyReason::Replayed];
        yield 'Expired' => [VerifyReason::Expired];
        yield 'NotEnrolled' => [VerifyReason::NotEnrolled];
        yield 'RateLimited' => [VerifyReason::RateLimited];
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new Verify2faResult(
            verified: true,
            reason: VerifyReason::Valid,
            purpose: TwoFactorPurpose::StepUp,
            acceptedTimeStep: 99,
        );

        self::assertTrue($result->verified);
        self::assertSame(VerifyReason::Valid, $result->reason);
        self::assertSame(TwoFactorPurpose::StepUp, $result->purpose);
        self::assertSame(99, $result->acceptedTimeStep);
    }

    #[Test]
    public function acceptedTimeStepDefaultsToNull(): void
    {
        $result = new Verify2faResult(
            verified: false,
            reason: VerifyReason::InvalidCode,
            purpose: TwoFactorPurpose::Login,
        );

        self::assertNull($result->acceptedTimeStep);
    }

    #[Test]
    public function failureAcrossAllPurposes(): void
    {
        foreach (TwoFactorPurpose::cases() as $purpose) {
            $result = Verify2faResult::failure(VerifyReason::InvalidCode, $purpose);

            self::assertFalse($result->verified);
            self::assertSame($purpose, $result->purpose);
            self::assertNull($result->acceptedTimeStep);
        }
    }
}
