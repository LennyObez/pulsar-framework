<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\Severity;

final class SeverityTest extends TestCase
{
    #[Test]
    #[DataProvider('allCasesProvider')]
    public function allCasesHaveStringValues(Severity $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{Severity, string}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'Error' => [Severity::Error, 'error'];
        yield 'Warning' => [Severity::Warning, 'warning'];
        yield 'Info' => [Severity::Info, 'info'];
    }

    #[Test]
    public function casesReturnsThreeValues(): void
    {
        self::assertCount(3, Severity::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(Severity::tryFrom('critical'));
    }
}
