<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\TypeBridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\TypeBridge\PhpToTypeScriptMapper;

#[CoversClass(PhpToTypeScriptMapper::class)]
final class PhpToTypeScriptMapperTest extends TestCase
{
    private PhpToTypeScriptMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new PhpToTypeScriptMapper();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scalarTypesProvider(): iterable
    {
        yield 'string' => ['string', 'string'];
        yield 'int' => ['int', 'number'];
        yield 'integer' => ['integer', 'number'];
        yield 'float' => ['float', 'number'];
        yield 'double' => ['double', 'number'];
        yield 'bool' => ['bool', 'boolean'];
        yield 'boolean' => ['boolean', 'boolean'];
        yield 'null' => ['null', 'null'];
        yield 'void' => ['void', 'void'];
        yield 'mixed' => ['mixed', 'unknown'];
        yield 'array' => ['array', 'unknown[]'];
        yield 'never' => ['never', 'never'];
        yield 'true' => ['true', 'true'];
        yield 'false' => ['false', 'false'];
        yield 'object' => ['object', 'Record<string, unknown>'];
    }

    #[Test]
    #[DataProvider('scalarTypesProvider')]
    public function maps_scalar_types(string $phpType, string $expected): void
    {
        self::assertSame($expected, $this->mapper->map($phpType));
    }

    #[Test]
    public function maps_nullable_types(): void
    {
        self::assertSame('string | null', $this->mapper->map('?string'));
        self::assertSame('number | null', $this->mapper->map('?int'));
    }

    #[Test]
    public function maps_array_syntax(): void
    {
        self::assertSame('string[]', $this->mapper->map('string[]'));
        self::assertSame('number[]', $this->mapper->map('int[]'));
    }

    #[Test]
    public function maps_list_generic(): void
    {
        self::assertSame('string[]', $this->mapper->map('list<string>'));
    }

    #[Test]
    public function maps_array_generic_with_key_value(): void
    {
        self::assertSame('Record<string, number>', $this->mapper->map('array<string, int>'));
    }

    #[Test]
    public function maps_union_types(): void
    {
        self::assertSame('string | number', $this->mapper->map('string|int'));
    }

    #[Test]
    public function maps_fqcn_to_short_name(): void
    {
        self::assertSame('User', $this->mapper->map('App\\Entity\\User'));
    }

    #[Test]
    public function maps_bare_class_name(): void
    {
        self::assertSame('UserDto', $this->mapper->map('UserDto'));
    }

    #[Test]
    public function maps_intersection(): void
    {
        self::assertSame('Serializable & Countable', $this->mapper->map('Serializable&Countable'));
    }
}
