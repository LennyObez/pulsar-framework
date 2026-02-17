<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Region;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Region\Continent;

use function count;

#[CoversClass(Continent::class)]
final class ContinentTest extends TestCase
{
    #[Test]
    public function allSevenContinentsExist(): void
    {
        self::assertCount(7, Continent::cases());
    }

    #[Test]
    #[DataProvider('continentValueProvider')]
    public function backingValuesAreSnakeCase(Continent $continent, string $expectedValue): void
    {
        self::assertSame($expectedValue, $continent->value);
    }

    /**
     * @return iterable<string, array{Continent, string}>
     */
    public static function continentValueProvider(): iterable
    {
        yield 'Africa' => [Continent::Africa, 'africa'];
        yield 'Asia' => [Continent::Asia, 'asia'];
        yield 'Europe' => [Continent::Europe, 'europe'];
        yield 'NorthAmerica' => [Continent::NorthAmerica, 'north_america'];
        yield 'SouthAmerica' => [Continent::SouthAmerica, 'south_america'];
        yield 'Oceania' => [Continent::Oceania, 'oceania'];
        yield 'Antarctica' => [Continent::Antarctica, 'antarctica'];
    }

    #[Test]
    #[DataProvider('continentLabelProvider')]
    public function labelReturnsHumanReadableName(Continent $continent, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $continent->label());
    }

    /**
     * @return iterable<string, array{Continent, string}>
     */
    public static function continentLabelProvider(): iterable
    {
        yield 'Africa' => [Continent::Africa, 'Africa'];
        yield 'Asia' => [Continent::Asia, 'Asia'];
        yield 'Europe' => [Continent::Europe, 'Europe'];
        yield 'NorthAmerica' => [Continent::NorthAmerica, 'North America'];
        yield 'SouthAmerica' => [Continent::SouthAmerica, 'South America'];
        yield 'Oceania' => [Continent::Oceania, 'Oceania'];
        yield 'Antarctica' => [Continent::Antarctica, 'Antarctica'];
    }

    #[Test]
    public function europeHasLowestSortOrder(): void
    {
        $min = PHP_INT_MAX;
        $minContinent = null;

        foreach (Continent::cases() as $continent) {
            if ($continent->sortOrder() < $min) {
                $min = $continent->sortOrder();
                $minContinent = $continent;
            }
        }

        self::assertSame(Continent::Europe, $minContinent);
    }

    #[Test]
    public function allSortOrdersAreUnique(): void
    {
        $orders = [];

        foreach (Continent::cases() as $continent) {
            $orders[] = $continent->sortOrder();
        }

        self::assertCount(count($orders), array_unique($orders));
    }

    #[Test]
    public function canBeCreatedFromValue(): void
    {
        $continent = Continent::from('europe');

        self::assertSame(Continent::Europe, $continent);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(Continent::tryFrom('atlantis'));
    }
}
