<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event;

use function count;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventVersion;
use ValueError;

#[CoversClass(EventVersion::class)]
final class EventVersionTest extends TestCase
{
    #[Test]
    public function v1CaseExists(): void
    {
        self::assertInstanceOf(EventVersion::class, EventVersion::V1);
    }

    #[Test]
    public function v1HasValueOf1(): void
    {
        self::assertSame(1, EventVersion::V1->value);
    }

    #[Test]
    public function enumIsIntegerBacked(): void
    {
        foreach (EventVersion::cases() as $case) {
            self::assertIsInt($case->value);
        }
    }

    #[Test]
    public function totalCaseCount(): void
    {
        // Currently only V1 exists
        self::assertCount(1, EventVersion::cases());
    }

    #[Test]
    public function casesCanBeIterated(): void
    {
        $cases = EventVersion::cases();

        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            self::assertInstanceOf(EventVersion::class, $case);
        }
    }

    #[Test]
    public function fromReturnsVersionForValidValue(): void
    {
        $version = EventVersion::from(1);

        self::assertSame(EventVersion::V1, $version);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $result = EventVersion::tryFrom(999);

        self::assertNull($result);
    }

    #[Test]
    public function tryFromReturnsVersionForValidValue(): void
    {
        $result = EventVersion::tryFrom(1);

        self::assertSame(EventVersion::V1, $result);
    }

    #[Test]
    public function valuePropertyReturnsIntegerValue(): void
    {
        $version = EventVersion::V1;

        self::assertIsInt($version->value);
        self::assertSame(1, $version->value);
    }

    #[Test]
    public function namePropertyReturnsEnumName(): void
    {
        $version = EventVersion::V1;

        self::assertSame('V1', $version->name);
    }

    #[Test]
    public function allCasesHaveUniqueValues(): void
    {
        $values = array_map(
            fn(EventVersion $version) => $version->value,
            EventVersion::cases(),
        );

        self::assertCount(count(EventVersion::cases()), array_unique($values));
    }

    #[Test]
    public function v1CanBeUsedInMatch(): void
    {
        $version = EventVersion::V1;

        $result = match ($version) {
            EventVersion::V1 => 'version-1',
        };

        self::assertSame('version-1', $result);
    }

    #[Test]
    public function v1CanBeCompared(): void
    {
        $v1 = EventVersion::V1;
        $anotherV1 = EventVersion::V1;

        self::assertSame($v1, $anotherV1);
    }

    #[Test]
    public function versionCanBeUsedAsArrayKey(): void
    {
        $schemas = [
            EventVersion::V1->value => 'schema-v1',
        ];

        self::assertSame('schema-v1', $schemas[EventVersion::V1->value]);
    }

    #[Test]
    public function versionValueIsPositive(): void
    {
        foreach (EventVersion::cases() as $case) {
            self::assertGreaterThan(0, $case->value);
        }
    }

    #[Test]
    public function tryFromReturnsNullForZero(): void
    {
        $result = EventVersion::tryFrom(0);

        self::assertNull($result);
    }

    #[Test]
    public function tryFromReturnsNullForNegative(): void
    {
        $result = EventVersion::tryFrom(-1);

        self::assertNull($result);
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        EventVersion::from(999);
    }
}
