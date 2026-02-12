<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\SchemaInferrer;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\AllTypesDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\DateTimeDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\EmptyDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\MixedPropertyDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\NestedRefDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\NullablePropertyDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\StaticPropertyDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\UnionTypeDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\WithApiFieldDto;
use Pulsar\Tests\Unit\Api\OpenApi\Fixture\WithApiFieldFullDto;
use stdClass;

#[CoversClass(SchemaInferrer::class)]
final class SchemaInferrerTest extends TestCase
{
    private SchemaInferrer $inferrer;

    protected function setUp(): void
    {
        $this->inferrer = new SchemaInferrer();
    }

    // --- Basic type inference ---

    #[Test]
    public function inferStringProperty(): void
    {
        $schema = $this->inferrer->infer(AllTypesDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $name */
        $name = $properties['name'];
        self::assertSame('string', $name['type']);
    }

    #[Test]
    public function inferIntProperty(): void
    {
        $schema = $this->inferrer->infer(AllTypesDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $age */
        $age = $properties['age'];
        self::assertSame('integer', $age['type']);
    }

    #[Test]
    public function inferFloatProperty(): void
    {
        $schema = $this->inferrer->infer(AllTypesDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $score */
        $score = $properties['score'];
        self::assertSame('number', $score['type']);
        self::assertSame('double', $score['format']);
    }

    #[Test]
    public function inferBoolProperty(): void
    {
        $schema = $this->inferrer->infer(AllTypesDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $active */
        $active = $properties['active'];
        self::assertSame('boolean', $active['type']);
    }

    #[Test]
    public function inferArrayProperty(): void
    {
        $schema = $this->inferrer->infer(AllTypesDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $tags */
        $tags = $properties['tags'];
        self::assertSame('array', $tags['type']);
        self::assertInstanceOf(stdClass::class, $tags['items']);
    }

    // --- Required fields ---

    #[Test]
    public function nonNullableNonDefaultPropertiesAreRequired(): void
    {
        $schema = $this->inferrer->infer(AllTypesDto::class);

        /** @var list<string> $required */
        $required = $schema['required'];
        self::assertContains('name', $required);
        self::assertContains('age', $required);
    }

    #[Test]
    public function emptyClassProducesObjectSchemaWithNoProperties(): void
    {
        $schema = $this->inferrer->infer(EmptyDto::class);

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['properties']);
        self::assertArrayNotHasKey('required', $schema);
    }

    // --- Nullable and mixed types ---

    #[Test]
    public function nullablePropertyGetsNullableFlag(): void
    {
        $schema = $this->inferrer->infer(NullablePropertyDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $label */
        $label = $properties['label'];
        self::assertTrue($label['nullable']);
        self::assertSame('string', $label['type']);
    }

    #[Test]
    public function nullablePropertyIsNotRequired(): void
    {
        $schema = $this->inferrer->infer(NullablePropertyDto::class);

        self::assertArrayNotHasKey('required', $schema);
    }

    #[Test]
    public function mixedTypeProducesEmptySchema(): void
    {
        $schema = $this->inferrer->infer(MixedPropertyDto::class);

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertSame([], $properties['data']);
    }

    // --- Static properties excluded ---

    #[Test]
    public function staticPropertiesAreExcluded(): void
    {
        $schema = $this->inferrer->infer(StaticPropertyDto::class);

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayNotHasKey('counter', $properties);
        self::assertArrayHasKey('value', $properties);
    }

    // --- DateTime types ---

    #[Test]
    #[DataProvider('dateTimeClassProvider')]
    public function dateTimeTypesResolveToDateTimeFormat(string $fixtureClass, string $propertyName): void
    {
        /** @var class-string $fixtureClass */
        $schema = $this->inferrer->infer($fixtureClass);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $prop */
        $prop = $properties[$propertyName];
        self::assertSame('string', $prop['type']);
        self::assertSame('date-time', $prop['format']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dateTimeClassProvider(): iterable
    {
        yield 'DateTimeImmutable' => [DateTimeDto::class, 'createdAt'];
        yield 'DateTime' => [DateTimeDto::class, 'updatedAt'];
    }

    // --- Enum types ---

    #[Test]
    public function stringBackedEnumInfersStringTypeWithValues(): void
    {
        $schema = $this->inferrer->infer(
            Fixture\EnumPropertyDto::class,
        );

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $statusSchema */
        $statusSchema = $properties['status'];
        self::assertSame('string', $statusSchema['type']);
        self::assertSame(['active', 'inactive', 'suspended'], $statusSchema['enum']);
    }

    #[Test]
    public function intBackedEnumInfersIntegerTypeWithValues(): void
    {
        $schema = $this->inferrer->infer(
            Fixture\IntEnumPropertyDto::class,
        );

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $prioritySchema */
        $prioritySchema = $properties['priority'];
        self::assertSame('integer', $prioritySchema['type']);
        self::assertSame([1, 2, 3], $prioritySchema['enum']);
    }

    #[Test]
    public function unitEnumInfersStringTypeWithCaseNames(): void
    {
        $schema = $this->inferrer->infer(
            Fixture\UnitEnumPropertyDto::class,
        );

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $colorSchema */
        $colorSchema = $properties['color'];
        self::assertSame('string', $colorSchema['type']);
        self::assertSame(['Red', 'Green', 'Blue'], $colorSchema['enum']);
    }

    // --- Nested class references ---

    #[Test]
    public function nestedClassResolvesToSchemaRef(): void
    {
        $schema = $this->inferrer->infer(NestedRefDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $nested */
        $nested = $properties['nested'];
        self::assertSame('#/components/schemas/AllTypesDto', $nested['$ref']);
    }

    // --- Union types ---

    #[Test]
    public function singleTypeUnionWithNullResolvesAsNullableSingleType(): void
    {
        $schema = $this->inferrer->infer(UnionTypeDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $labelSchema */
        $labelSchema = $properties['label'];
        self::assertSame('string', $labelSchema['type']);
        self::assertTrue($labelSchema['nullable']);
    }

    #[Test]
    public function multiTypeUnionResolvesAsOneOf(): void
    {
        $schema = $this->inferrer->infer(UnionTypeDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $valueSchema */
        $valueSchema = $properties['value'];
        self::assertArrayHasKey('oneOf', $valueSchema);
        /** @var list<array<string, string>> $oneOf */
        $oneOf = $valueSchema['oneOf'];
        self::assertCount(2, $oneOf);

        $types = array_column($oneOf, 'type');
        self::assertContains('string', $types);
        self::assertContains('integer', $types);
    }

    // --- ApiField attribute ---

    #[Test]
    public function apiFieldClassificationEmitsVendorExtension(): void
    {
        $schema = $this->inferrer->infer(WithApiFieldDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $secret */
        $secret = $properties['secret'];
        self::assertSame('confidential', $secret['x-pulsar-classification']);
    }

    #[Test]
    public function apiFieldRedactedEmitsVendorExtension(): void
    {
        $schema = $this->inferrer->infer(WithApiFieldDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $secret */
        $secret = $properties['secret'];
        self::assertTrue($secret['x-pulsar-redacted']);
    }

    #[Test]
    public function apiFieldFullMetadataEmitsAllExtensions(): void
    {
        $schema = $this->inferrer->infer(WithApiFieldFullDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $prop */
        $prop = $properties['ssn'];
        self::assertSame('restricted', $prop['x-pulsar-classification']);
        self::assertSame('auditor', $prop['x-pulsar-access-level']);
        self::assertTrue($prop['x-pulsar-redacted']);
        self::assertSame('Social Security Number', $prop['description']);
        self::assertSame('123-45-6789', $prop['example']);
    }

    #[Test]
    public function apiFieldWithoutOptionalFieldsDoesNotEmitThem(): void
    {
        $schema = $this->inferrer->infer(WithApiFieldDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $prop */
        $prop = $properties['secret'];

        self::assertArrayNotHasKey('x-pulsar-access-level', $prop);
        self::assertArrayNotHasKey('example', $prop);
    }

    // --- Caching ---

    #[Test]
    public function inferCachesSchemaForSameClass(): void
    {
        $first = $this->inferrer->infer(AllTypesDto::class);
        $second = $this->inferrer->infer(AllTypesDto::class);

        self::assertSame($first, $second);
    }

    // --- shortName ---

    #[Test]
    #[DataProvider('shortNameProvider')]
    public function shortNameExtractsLastSegment(string $fqcn, string $expected): void
    {
        /** @var class-string $fqcn */
        self::assertSame($expected, $this->inferrer->shortName($fqcn));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function shortNameProvider(): iterable
    {
        yield 'fully qualified' => [SchemaInferrer::class, 'SchemaInferrer'];
        yield 'single segment' => [stdClass::class, 'stdClass'];
        yield 'deep namespace' => [DateTimeDto::class, 'DateTimeDto'];
    }

    // --- Untyped property ---

    #[Test]
    public function untypedPropertyProducesEmptySchema(): void
    {
        $schema = $this->inferrer->infer(Fixture\UntypedPropertyDto::class);

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertSame([], $properties['anything']);
    }

    // --- Null type specifically ---

    #[Test]
    public function explicitNullTypePropertyProducesNullableString(): void
    {
        $schema = $this->inferrer->infer(Fixture\NullTypePropertyDto::class);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $prop */
        $prop = $properties['nothing'];
        self::assertSame('string', $prop['type']);
        self::assertTrue($prop['nullable']);
    }

    // --- Property with default value ---

    #[Test]
    public function propertyWithDefaultValueIsNotRequired(): void
    {
        $schema = $this->inferrer->infer(Fixture\DefaultValueDto::class);

        if (isset($schema['required'])) {
            /** @var list<string> $required */
            $required = $schema['required'];
            self::assertNotContains('label', $required);
        } else {
            // No required array at all — all have defaults
            self::assertArrayNotHasKey('required', $schema);
        }
    }
}
