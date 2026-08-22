<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Assertion;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Assertion\SecurityViolation;
use Pulsar\Security\Assertion\SecurityViolationSeverity;

final class SecurityViolationTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $violation = new SecurityViolation(
            'test_assertion',
            'Something is wrong',
            SecurityViolationSeverity::High,
        );

        self::assertSame('test_assertion', $violation->assertion);
        self::assertSame('Something is wrong', $violation->message);
        self::assertSame(SecurityViolationSeverity::High, $violation->severity);
    }

    #[Test]
    public function severityEnumHasExpectedCases(): void
    {
        self::assertSame('critical', SecurityViolationSeverity::Critical->value);
        self::assertSame('high', SecurityViolationSeverity::High->value);
        self::assertSame('medium', SecurityViolationSeverity::Medium->value);
        self::assertSame('low', SecurityViolationSeverity::Low->value);
    }
}
