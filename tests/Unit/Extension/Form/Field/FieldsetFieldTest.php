<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\FieldsetField;
use Pulsar\Extension\Form\Field\TextField;

#[CoversClass(FieldsetField::class)]
final class FieldsetFieldTest extends TestCase
{
    #[Test]
    public function basicProperties(): void
    {
        $field = new FieldsetField(
            name: 'address',
            label: 'Address',
            legend: 'Shipping Address',
        );

        self::assertSame('address', $field->getName());
        self::assertSame('Address', $field->getLabel());
        self::assertSame('fieldset', $field->getType());
        self::assertSame('Shipping Address', $field->getLegend());
        self::assertNull($field->getValue());
        self::assertFalse($field->isRequired());
        self::assertFalse($field->isDisabled());
        self::assertSame([], $field->getAttributes());
        self::assertSame([], $field->getRules());
    }

    #[Test]
    public function labelFallsBackToNameWhenEmpty(): void
    {
        $field = new FieldsetField(name: 'address');

        self::assertSame('address', $field->getLabel());
    }

    #[Test]
    public function legendFallsBackToLabelWhenEmpty(): void
    {
        $field = new FieldsetField(name: 'address', label: 'Address');

        self::assertSame('Address', $field->getLegend());
    }

    #[Test]
    public function addAndGetChild(): void
    {
        $field = new FieldsetField(name: 'address');
        $street = new TextField('street', 'Street');
        $city = new TextField('city', 'City');

        $field->add($street)->add($city);

        self::assertSame($street, $field->getChild('street'));
        self::assertSame($city, $field->getChild('city'));
        self::assertNull($field->getChild('state'));
        self::assertCount(2, $field->getChildren());
    }

    #[Test]
    public function removeChild(): void
    {
        $field = new FieldsetField(name: 'address');
        $field->add(new TextField('street', 'Street'));
        $field->add(new TextField('city', 'City'));

        $field->remove('street');

        self::assertNull($field->getChild('street'));
        self::assertNotNull($field->getChild('city'));
        self::assertCount(1, $field->getChildren());
    }

    #[Test]
    public function setValueCascadesToChildren(): void
    {
        $field = new FieldsetField(name: 'address');
        $street = new TextField('street', 'Street');
        $city = new TextField('city', 'City');
        $field->add($street)->add($city);

        $field->setValue(['street' => '123 Main St', 'city' => 'Springfield']);

        self::assertSame('123 Main St', $street->getValue());
        self::assertSame('Springfield', $city->getValue());
    }

    #[Test]
    public function setValueNonArrayDoesNotCascade(): void
    {
        $field = new FieldsetField(name: 'address');
        $street = new TextField('street', 'Street');
        $field->add($street);

        $field->setValue('not-array');

        self::assertNull($street->getValue());
    }

    #[Test]
    public function setValueSkipsUnknownChildren(): void
    {
        $field = new FieldsetField(name: 'address');
        $street = new TextField('street', 'Street');
        $field->add($street);

        $field->setValue(['street' => 'Main', 'unknown' => 'ignored']);

        self::assertSame('Main', $street->getValue());
    }

    #[Test]
    public function getIdAndErrorId(): void
    {
        $field = new FieldsetField(name: 'address');

        self::assertSame('fieldset-address', $field->getId());
        self::assertSame('fieldset-address-error', $field->getErrorId());
    }
}
