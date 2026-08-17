<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Mapper;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Mapper\MappingException;
use Pulsar\Support\Mapper\NamingStrategy;
use Pulsar\Support\Mapper\ObjectMapper;

#[CoversClass(ObjectMapper::class)]
#[CoversClass(MappingException::class)]
final class ObjectMapperTest extends TestCase
{
    private ObjectMapper $mapper;

    protected function setUp(): void
    {
        ObjectMapper::clearCache();
        $this->mapper = new ObjectMapper();
    }

    public function testMapSimpleDto(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Alice', 'age' => 30],
            SimpleDto::class,
        );

        self::assertSame('Alice', $result->name);
        self::assertSame(30, $result->age);
    }

    public function testMapWithDefaultValues(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Bob'],
            DtoWithDefaults::class,
        );

        self::assertSame('Bob', $result->name);
        self::assertSame('unknown', $result->role);
    }

    public function testMapWithNullableField(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Charlie'],
            DtoWithNullable::class,
        );

        self::assertSame('Charlie', $result->name);
        self::assertNull($result->email);
    }

    public function testMapNestedDto(): void
    {
        $result = $this->mapper->map(
            ['title' => 'Post 1', 'author' => ['name' => 'Dave', 'age' => 25]],
            NestedDto::class,
        );

        self::assertSame('Post 1', $result->title);
        self::assertInstanceOf(SimpleDto::class, $result->author);
        self::assertSame('Dave', $result->author->name);
    }

    public function testMapBackedEnum(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Eve', 'status' => 'active'],
            DtoWithEnum::class,
        );

        self::assertSame(TestStatus::Active, $result->status);
    }

    public function testMapDateTimeImmutableFromString(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Frank', 'createdAt' => '2024-01-15T10:30:00+00:00'],
            DtoWithDateTime::class,
        );

        self::assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
        self::assertSame('2024-01-15', $result->createdAt->format('Y-m-d'));
    }

    public function testMapDateTimeImmutableFromTimestamp(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Grace', 'createdAt' => 1705312200],
            DtoWithDateTime::class,
        );

        self::assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
    }

    public function testMapListOfDtos(): void
    {
        $results = $this->mapper->mapList(
            [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
            SimpleDto::class,
        );

        self::assertCount(2, $results);
        self::assertSame('Alice', $results[0]->name);
        self::assertSame('Bob', $results[1]->name);
    }

    public function testCamelToSnakeStrategy(): void
    {
        $mapper = new ObjectMapper(NamingStrategy::CamelToSnake);

        $result = $mapper->map(
            ['first_name' => 'Alice', 'last_name' => 'Smith'],
            CamelCaseDto::class,
        );

        self::assertSame('Alice', $result->firstName);
        self::assertSame('Smith', $result->lastName);
    }

    public function testSnakeToCamelStrategy(): void
    {
        $mapper = new ObjectMapper(NamingStrategy::SnakeToCamel);

        $result = $mapper->map(
            ['firstName' => 'Alice', 'lastName' => 'Smith'],
            SnakeCaseDto::class,
        );

        self::assertSame('Alice', $result->first_name);
        self::assertSame('Smith', $result->last_name);
    }

    public function testDirectMatchTakesPriorityOverNamingStrategy(): void
    {
        $mapper = new ObjectMapper(NamingStrategy::CamelToSnake);

        $result = $mapper->map(
            ['firstName' => 'Direct', 'last_name' => 'Alternate'],
            CamelCaseDto::class,
        );

        self::assertSame('Direct', $result->firstName);
        self::assertSame('Alternate', $result->lastName);
    }

    public function testMapUsesFromArrayWhenAvailable(): void
    {
        $result = $this->mapper->map(
            ['value' => 42],
            DtoWithFactory::class,
        );

        self::assertSame(42, $result->value);
        self::assertTrue($result->fromFactory);
    }

    public function testThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains("Missing required parameter 'age'");

        (void) $this->mapper->map(['name' => 'Alice'], SimpleDto::class);
    }

    public function testThrowsOnInvalidEnumValue(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('Cannot map value');

        (void) $this->mapper->map(
            ['name' => 'Test', 'status' => 'nonexistent'],
            DtoWithEnum::class,
        );
    }

    public function testThrowsOnInvalidListItem(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('not an array');

        /** @var list<array<mixed>> $invalid */
        $invalid = [['name' => 'Alice', 'age' => 30], 'not-an-array'];
        (void) $this->mapper->mapList(
            $invalid,
            SimpleDto::class,
        );
    }

    public function testThrowsOnNonexistentClass(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        // Build a class-string that PHPStan cannot resolve to a real class
        /** @var class-string $classString */
        $classString = implode('\\', ['Nonexistent', 'FakeClass']);
        (void) $this->mapper->map(['a' => 1], $classString);
    }

    public function testMapEmptyDataToNoConstructorClass(): void
    {
        $result = $this->mapper->map([], NoConstructorDto::class);

        self::assertInstanceOf(NoConstructorDto::class, $result);
    }

    public function testClearCacheDoesNotBreakSubsequentMappings(): void
    {
        (void) $this->mapper->map(['name' => 'A', 'age' => 1], SimpleDto::class);
        ObjectMapper::clearCache();
        $result = $this->mapper->map(['name' => 'B', 'age' => 2], SimpleDto::class);

        self::assertSame('B', $result->name);
    }

    public function testMapNullToNullableField(): void
    {
        $result = $this->mapper->map(
            ['name' => 'Test', 'email' => null],
            DtoWithNullable::class,
        );

        self::assertNull($result->email);
    }

    public function testMapAlreadyCorrectTypePassesThrough(): void
    {
        $dt = new DateTimeImmutable('2024-06-01');

        $result = $this->mapper->map(
            ['name' => 'Test', 'createdAt' => $dt],
            DtoWithDateTime::class,
        );

        self::assertSame($dt, $result->createdAt);
    }
}

// Test fixtures

final class SimpleDto
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
    ) {}
}

final class DtoWithDefaults
{
    public function __construct(
        public readonly string $name,
        public readonly string $role = 'unknown',
    ) {}
}

final class DtoWithNullable
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $email = null,
    ) {}
}

final class NestedDto
{
    public function __construct(
        public readonly string $title,
        public readonly SimpleDto $author,
    ) {}
}

enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final class DtoWithEnum
{
    public function __construct(
        public readonly string $name,
        public readonly TestStatus $status,
    ) {}
}

final class DtoWithDateTime
{
    public function __construct(
        public readonly string $name,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}

final class CamelCaseDto
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
    ) {}
}

final class SnakeCaseDto
{
    public function __construct(
        public readonly string $first_name,
        public readonly string $last_name,
    ) {}
}

final class DtoWithFactory
{
    public readonly bool $fromFactory;

    public function __construct(
        public readonly int $value,
        bool $fromFactory = false,
    ) {
        $this->fromFactory = $fromFactory;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var int|string $value */
        $value = $data['value'] ?? 0;
        return new self((int) $value, true);
    }
}

final class NoConstructorDto {}
