<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\Confirm2faSetupResult;
use Pulsar\Auth\TwoFactor\VerifyReason;

#[CoversClass(Confirm2faSetupResult::class)]
final class Confirm2faSetupResultTest extends TestCase
{
    #[Test]
    public function successFactoryReturnsConfirmedResult(): void
    {
        $result = Confirm2faSetupResult::success();

        self::assertTrue($result->confirmed);
        self::assertSame(VerifyReason::Valid, $result->reason);
    }

    #[Test]
    #[DataProvider('failureReasonProvider')]
    public function failureFactoryReturnsUnconfirmedResultWithReason(VerifyReason $reason): void
    {
        $result = Confirm2faSetupResult::failure($reason);

        self::assertFalse($result->confirmed);
        self::assertSame($reason, $result->reason);
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
    public function constructorSetsProperties(): void
    {
        $result = new Confirm2faSetupResult(true, VerifyReason::Valid);

        self::assertTrue($result->confirmed);
        self::assertSame(VerifyReason::Valid, $result->reason);
    }

    #[Test]
    public function successAndFailureProduceDifferentResults(): void
    {
        $success = Confirm2faSetupResult::success();
        $failure = Confirm2faSetupResult::failure(VerifyReason::InvalidCode);

        self::assertNotSame($success->confirmed, $failure->confirmed);
        self::assertNotSame($success->reason, $failure->reason);
    }
}
