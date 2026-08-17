<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\TypeScriptType;

#[CoversNothing]
final class TypeScriptTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('typeValueProvider')]
    public function caseHasCorrectStringValue(TypeScriptType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{TypeScriptType, string}>
     */
    public static function typeValueProvider(): iterable
    {
        yield 'String' => [TypeScriptType::String, 'string'];
        yield 'Number' => [TypeScriptType::Number, 'number'];
        yield 'Boolean' => [TypeScriptType::Boolean, 'boolean'];
        yield 'Null' => [TypeScriptType::Null, 'null'];
        yield 'Undefined' => [TypeScriptType::Undefined, 'undefined'];
        yield 'Any' => [TypeScriptType::Any, 'any'];
        yield 'Unknown' => [TypeScriptType::Unknown, 'unknown'];
        yield 'Void' => [TypeScriptType::Void, 'void'];
        yield 'Never' => [TypeScriptType::Never, 'never'];
        yield 'Object' => [TypeScriptType::Object, 'Record<string, unknown>'];
        yield 'StringArray' => [TypeScriptType::StringArray, 'string[]'];
        yield 'NumberArray' => [TypeScriptType::NumberArray, 'number[]'];
    }

    #[Test]
    public function allCasesAreMapped(): void
    {
        self::assertCount(12, TypeScriptType::cases());
    }

    #[Test]
    public function fromReturnsCase(): void
    {
        self::assertSame(TypeScriptType::Boolean, TypeScriptType::from('boolean'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(TypeScriptType::tryFrom('bigint'));
    }
}
