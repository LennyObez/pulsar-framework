<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\SupplyChain\Vex;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\SupplyChain\Vex\VexStatus;
use ValueError;

use function sprintf;

#[CoversNothing]
final class VexStatusTest extends TestCase
{
    #[Test]
    public function enumHasExactlyFourCases(): void
    {
        self::assertCount(4, VexStatus::cases());
    }

    #[Test]
    #[DataProvider('caseValueProvider')]
    public function eachCaseHasExpectedBackingValue(VexStatus $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{VexStatus, string}>
     */
    public static function caseValueProvider(): iterable
    {
        yield 'NotAffected' => [VexStatus::NotAffected, 'not_affected'];
        yield 'Affected' => [VexStatus::Affected, 'affected'];
        yield 'Fixed' => [VexStatus::Fixed, 'fixed'];
        yield 'UnderInvestigation' => [VexStatus::UnderInvestigation, 'under_investigation'];
    }

    #[Test]
    #[DataProvider('caseValueProvider')]
    public function fromReturnsCorrectCaseForValidValue(VexStatus $expectedCase, string $value): void
    {
        self::assertSame($expectedCase, VexStatus::from($value));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(VexStatus::tryFrom('nonexistent'));
    }

    #[Test]
    public function tryFromReturnsNullForEmptyString(): void
    {
        self::assertNull(VexStatus::tryFrom(''));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        VexStatus::from('invalid_status');
    }

    #[Test]
    public function casesAreDistinct(): void
    {
        $values = array_map(
            static fn(VexStatus $case): string => $case->value,
            VexStatus::cases(),
        );

        self::assertSame($values, array_unique($values));
    }

    #[Test]
    public function backingValuesUseSnakeCaseConvention(): void
    {
        foreach (VexStatus::cases() as $case) {
            self::assertMatchesRegularExpression(
                '/^[a-z]+(_[a-z]+)*$/',
                $case->value,
                sprintf('Case %s backing value should use snake_case', $case->name),
            );
        }
    }
}
