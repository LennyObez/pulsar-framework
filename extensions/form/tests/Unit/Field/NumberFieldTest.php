<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\NumberField;

final class NumberFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsNumber(): void
    {
        self::assertSame('number', new NumberField('quantity')->getType());
    }

    #[Test]
    public function constraintsReturnProvidedValues(): void
    {
        $field = new NumberField('qty', 'Quantity', min: 1, max: 100, step: 5, placeholder: 'Enter qty');

        self::assertSame(1, $field->getMin());
        self::assertSame(100, $field->getMax());
        self::assertSame(5, $field->getStep());
        self::assertSame('Enter qty', $field->getPlaceholder());
    }

    #[Test]
    public function constraintsDefaultToNull(): void
    {
        $field = new NumberField('qty');

        self::assertNull($field->getMin());
        self::assertNull($field->getMax());
        self::assertNull($field->getStep());
        self::assertNull($field->getPlaceholder());
    }
}
