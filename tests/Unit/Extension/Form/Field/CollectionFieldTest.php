<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\CollectionField;
use Pulsar\Extension\Form\Field\TextField;

#[CoversClass(CollectionField::class)]
final class CollectionFieldTest extends TestCase
{
    #[Test]
    public function basicProperties(): void
    {
        $field = new CollectionField(
            name: 'phones',
            label: 'Phone Numbers',
            prototype: static fn() => new TextField('phone', 'Phone'),
        );

        self::assertSame('phones', $field->getName());
        self::assertSame('Phone Numbers', $field->getLabel());
        self::assertSame('collection', $field->getType());
        self::assertNull($field->getValue());
        self::assertSame([], $field->getEntries());
        self::assertFalse($field->isRequired());
        self::assertFalse($field->isDisabled());
        self::assertSame([], $field->getAttributes());
        self::assertSame([], $field->getRules());
    }

    #[Test]
    public function minMaxEntries(): void
    {
        $field = new CollectionField(
            name: 'items',
            label: 'Items',
            prototype: static fn() => new TextField('item', 'Item'),
            minEntries: 1,
            maxEntries: 5,
        );

        self::assertSame(1, $field->getMinEntries());
        self::assertSame(5, $field->getMaxEntries());
        self::assertTrue($field->isRequired());
    }

    #[Test]
    public function addEntry(): void
    {
        $field = new CollectionField(
            name: 'items',
            label: 'Items',
            prototype: static fn() => new TextField('item', 'Item'),
        );

        $entry = $field->addEntry();

        self::assertCount(1, $field->getEntries());
        self::assertSame($entry, $field->getEntries()[0]);
    }

    #[Test]
    public function removeEntry(): void
    {
        $field = new CollectionField(
            name: 'items',
            label: 'Items',
            prototype: static fn() => new TextField('item', 'Item'),
        );

        $field->addEntry();
        $field->addEntry();
        self::assertCount(2, $field->getEntries());

        $field->removeEntry(0);
        self::assertCount(1, $field->getEntries());
    }

    #[Test]
    public function setValueCreatesEntries(): void
    {
        $field = new CollectionField(
            name: 'items',
            label: 'Items',
            prototype: static fn() => new TextField('item', 'Item'),
        );

        $field->setValue(['first', 'second', 'third']);

        self::assertCount(3, $field->getEntries());
        self::assertSame('first', $field->getEntries()[0]->getValue());
        self::assertSame('second', $field->getEntries()[1]->getValue());
        self::assertSame('third', $field->getEntries()[2]->getValue());
    }

    #[Test]
    public function setValueNonArrayDoesNotCreateEntries(): void
    {
        $field = new CollectionField(
            name: 'items',
            label: 'Items',
            prototype: static fn() => new TextField('item', 'Item'),
        );

        $field->setValue('not-array');

        self::assertSame([], $field->getEntries());
    }

    #[Test]
    public function getIdAndErrorId(): void
    {
        $field = new CollectionField(
            name: 'phones',
            label: 'Phones',
            prototype: static fn() => new TextField('phone', 'Phone'),
        );

        self::assertSame('collection-phones', $field->getId());
        self::assertSame('collection-phones-error', $field->getErrorId());
    }

    #[Test]
    public function maxEntriesDefaultsToNull(): void
    {
        $field = new CollectionField(
            name: 'items',
            label: 'Items',
            prototype: static fn() => new TextField('item', 'Item'),
        );

        self::assertNull($field->getMaxEntries());
    }
}
