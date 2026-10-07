<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\NumberField;

#[CoversClass(NumberField::class)]
final class NumberFieldTest extends TestCase
{
    #[Test]
    public function typeIsNumber(): void
    {
        $field = new NumberField('quantity');
        self::assertSame('number', $field->getType());
    }

    #[Test]
    public function defaultsAreNull(): void
    {
        $field = new NumberField('qty');

        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
        self::assertNull($field->getStep());
        self::assertNull($field->getPlaceholder());
    }

    #[Test]
    public function integerBounds(): void
    {
        $field = new NumberField('age', 'Age', min: 0, max: 150, step: 1);

        self::assertSame(0, $field->getMin());
        self::assertSame(150, $field->getMax());
        self::assertSame(1, $field->getStep());
    }

    #[Test]
    public function floatBounds(): void
    {
        $field = new NumberField('price', 'Price', min: 0.01, max: 9999.99, step: 0.01);

        self::assertSame(0.01, $field->getMin());
        self::assertSame(9999.99, $field->getMax());
        self::assertSame(0.01, $field->getStep());
    }

    #[Test]
    public function placeholderIsConfigurable(): void
    {
        $field = new NumberField('amount', 'Amount', placeholder: 'Enter amount');
        self::assertSame('Enter amount', $field->getPlaceholder());
    }
}
