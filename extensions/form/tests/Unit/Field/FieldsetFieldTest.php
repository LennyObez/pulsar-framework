<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\FieldsetField;
use Pulsar\Extension\Form\Field\TextField;

final class FieldsetFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsFieldset(): void
    {
        self::assertSame('fieldset', new FieldsetField('address')->getType());
    }

    #[Test]
    public function addAndGetChildField(): void
    {
        $fieldset = new FieldsetField('address', 'Address');
        $child = new TextField('city', 'City');
        $fieldset->add($child);

        self::assertSame($child, $fieldset->getChild('city'));
        self::assertNull($fieldset->getChild('nonexistent'));
    }

    #[Test]
    public function removeChildField(): void
    {
        $fieldset = new FieldsetField('address');
        $fieldset->add(new TextField('city', 'City'));
        $fieldset->remove('city');

        self::assertNull($fieldset->getChild('city'));
        self::assertEmpty($fieldset->getChildren());
    }

    #[Test]
    public function setValueCascadesToChildren(): void
    {
        $fieldset = new FieldsetField('address');
        $city = new TextField('city');
        $zip = new TextField('zip');
        $fieldset->add($city)->add($zip);

        $fieldset->setValue(['city' => 'Paris', 'zip' => '75001']);

        self::assertSame('Paris', $city->getValue());
        self::assertSame('75001', $zip->getValue());
    }

    #[Test]
    public function legendFallsBackToLabel(): void
    {
        $fieldset = new FieldsetField('address', 'Address', 'Shipping Address');

        self::assertSame('Shipping Address', $fieldset->getLegend());
    }

    #[Test]
    public function legendFallsBackToLabelWhenLegendIsEmpty(): void
    {
        $fieldset = new FieldsetField('address', 'Address');

        self::assertSame('Address', $fieldset->getLegend());
    }

    #[Test]
    public function idUsesFieldsetPrefix(): void
    {
        $fieldset = new FieldsetField('billing');

        self::assertSame('fieldset-billing', $fieldset->getId());
        self::assertSame('fieldset-billing-error', $fieldset->getErrorId());
    }
}
