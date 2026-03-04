<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\RegressionViolation;

#[CoversClass(RegressionViolation::class)]
final class RegressionViolationTest extends TestCase
{
    public function testConstruction(): void
    {
        $violation = new RegressionViolation(
            constraint: 'session.idle_timeout',
            expectedDescription: '<= 900 seconds',
            actualDescription: '1800 seconds',
            remediation: 'Set to 900.',
        );

        self::assertSame('session.idle_timeout', $violation->constraint);
        self::assertSame('<= 900 seconds', $violation->expectedDescription);
        self::assertSame('1800 seconds', $violation->actualDescription);
        self::assertSame('Set to 900.', $violation->remediation);
    }

    public function testToArray(): void
    {
        $violation = new RegressionViolation(
            constraint: 'auth.password_min_length',
            expectedDescription: '>= 12 characters',
            actualDescription: '8 characters',
            remediation: 'Increase to 12.',
        );

        $array = $violation->toArray();

        self::assertSame('auth.password_min_length', $array['constraint']);
        self::assertSame('>= 12 characters', $array['expected']);
        self::assertSame('8 characters', $array['actual']);
        self::assertSame('Increase to 12.', $array['remediation']);
    }
}
