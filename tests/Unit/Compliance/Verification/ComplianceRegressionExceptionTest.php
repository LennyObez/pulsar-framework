<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\ComplianceRegressionException;
use Pulsar\Compliance\Verification\RegressionViolation;

#[CoversClass(ComplianceRegressionException::class)]
final class ComplianceRegressionExceptionTest extends TestCase
{
    public function testFromViolationsSingle(): void
    {
        $violation = new RegressionViolation(
            constraint: 'session.idle_timeout',
            expectedDescription: '<= 900 seconds',
            actualDescription: '1800 seconds',
            remediation: 'Set to 900.',
        );

        $exception = ComplianceRegressionException::fromViolations([$violation]);

        self::assertStringContainsString('1 violation', $exception->getMessage());
        self::assertStringContainsString('session.idle_timeout', $exception->getMessage());
        self::assertCount(1, $exception->violations());
        self::assertSame($violation, $exception->violations()[0]);
    }

    public function testFromViolationsMultiple(): void
    {
        $v1 = new RegressionViolation('a', 'b', 'c', 'd');
        $v2 = new RegressionViolation('e', 'f', 'g', 'h');

        $exception = ComplianceRegressionException::fromViolations([$v1, $v2]);

        self::assertStringContainsString('2 violations', $exception->getMessage());
        self::assertCount(2, $exception->violations());
    }
}
