<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\FieldDefinition;

#[CoversClass(FieldDefinition::class)]
final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function defaultsNullableAndPrimaryToFalse(): void
    {
        $field = new FieldDefinition(name: 'id', type: 'int');

        self::assertFalse($field->nullable);
        self::assertFalse($field->primary);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function flagCombinationsProvider(): iterable
    {
        yield 'nullable only' => [true, false];
        yield 'primary only' => [false, true];
        yield 'both flags' => [true, true];
        yield 'neither flag' => [false, false];
    }

    #[Test]
    #[DataProvider('flagCombinationsProvider')]
    public function flagCombinationsArePreserved(bool $nullable, bool $primary): void
    {
        $field = new FieldDefinition(
            name: 'field',
            type: 'string',
            nullable: $nullable,
            primary: $primary,
        );

        self::assertSame($nullable, $field->nullable);
        self::assertSame($primary, $field->primary);
    }
}
