<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\VerificationResult;

#[CoversClass(VerificationResult::class)]
final class VerificationResultTest extends TestCase
{
    #[Test]
    public function passFactoryReturnsPassedResult(): void
    {
        $result = VerificationResult::pass('CC6.1', 'MFA is enabled');

        self::assertSame('CC6.1', $result->controlId);
        self::assertTrue($result->passed);
        self::assertSame('MFA is enabled', $result->message);
        self::assertNotNull($result->verifiedAt);
        self::assertEqualsWithDelta(time(), $result->verifiedAt, 2);
    }

    #[Test]
    public function passFactoryUsesDefaultMessage(): void
    {
        $result = VerificationResult::pass('CTRL-1');

        self::assertSame('Control verified', $result->message);
    }

    #[Test]
    public function failFactoryReturnsFailedResult(): void
    {
        $result = VerificationResult::fail('CC7.1', 'Audit logging not active');

        self::assertSame('CC7.1', $result->controlId);
        self::assertFalse($result->passed);
        self::assertSame('Audit logging not active', $result->message);
        self::assertNotNull($result->verifiedAt);
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new VerificationResult(
            controlId: 'HIPAA-164.312',
            passed: true,
            message: 'Encryption at rest verified',
            verifiedAt: 1700000000,
        );

        self::assertSame('HIPAA-164.312', $result->controlId);
        self::assertTrue($result->passed);
        self::assertSame('Encryption at rest verified', $result->message);
        self::assertSame(1700000000, $result->verifiedAt);
    }

    #[Test]
    public function constructorDefaultsMessageToEmpty(): void
    {
        $result = new VerificationResult(
            controlId: 'TEST-1',
            passed: false,
        );

        self::assertSame('', $result->message);
        self::assertNull($result->verifiedAt);
    }

    #[Test]
    public function passAndFailTimestampsAreRecent(): void
    {
        $before = time();
        $pass = VerificationResult::pass('T-1');
        $fail = VerificationResult::fail('T-2', 'reason');
        $after = time();

        self::assertGreaterThanOrEqual($before, $pass->verifiedAt);
        self::assertLessThanOrEqual($after, $pass->verifiedAt);
        self::assertGreaterThanOrEqual($before, $fail->verifiedAt);
        self::assertLessThanOrEqual($after, $fail->verifiedAt);
    }
}
