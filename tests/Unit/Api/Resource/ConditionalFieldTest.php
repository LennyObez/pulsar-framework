<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\ConditionalField;

#[CoversClass(ConditionalField::class)]
final class ConditionalFieldTest extends TestCase
{
    #[Test]
    public function when_includes_field_when_condition_true(): void
    {
        $field = ConditionalField::when(true, 'secret-value');

        self::assertTrue($field->include);
        self::assertSame('secret-value', $field->value);
    }

    #[Test]
    public function when_excludes_field_when_condition_false(): void
    {
        $field = ConditionalField::when(false, 'secret-value');

        self::assertFalse($field->include);
        self::assertSame('secret-value', $field->value);
    }

    #[Test]
    public function unless_includes_field_when_condition_false(): void
    {
        $field = ConditionalField::unless(false, 42);

        self::assertTrue($field->include);
        self::assertSame(42, $field->value);
    }

    #[Test]
    public function unless_excludes_field_when_condition_true(): void
    {
        $field = ConditionalField::unless(true, 42);

        self::assertFalse($field->include);
    }

    #[Test]
    public function constructor_stores_value_and_include(): void
    {
        $field = new ConditionalField(value: ['nested' => 'data'], include: true);

        self::assertSame(['nested' => 'data'], $field->value);
        self::assertTrue($field->include);
    }

    #[Test]
    public function value_can_be_null(): void
    {
        $field = ConditionalField::when(true, null);

        self::assertNull($field->value);
        self::assertTrue($field->include);
    }
}
