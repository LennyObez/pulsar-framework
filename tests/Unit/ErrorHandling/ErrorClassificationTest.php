<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\ErrorClassification;
use ValueError;

#[CoversNothing]
final class ErrorClassificationTest extends TestCase
{
    #[Test]
    #[DataProvider('classificationProvider')]
    public function backingValuesAreCorrectStrings(ErrorClassification $classification, string $expectedValue): void
    {
        self::assertSame($expectedValue, $classification->value);
    }

    /**
     * @return iterable<string, array{ErrorClassification, string}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'Transient' => [ErrorClassification::Transient, 'transient'];
        yield 'Permanent' => [ErrorClassification::Permanent, 'permanent'];
        yield 'Validation' => [ErrorClassification::Validation, 'validation'];
        yield 'Security' => [ErrorClassification::Security, 'security'];
    }

    #[Test]
    public function allCasesAreDefined(): void
    {
        $cases = ErrorClassification::cases();
        self::assertCount(4, $cases);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $result = ErrorClassification::tryFrom('nonexistent');
        self::assertNull($result);
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);
        ErrorClassification::from('invalid');
    }

    #[Test]
    public function casesAreDistinct(): void
    {
        $values = array_map(
            static fn(ErrorClassification $c): string => $c->value,
            ErrorClassification::cases(),
        );

        self::assertSame($values, array_unique($values));
    }
}
