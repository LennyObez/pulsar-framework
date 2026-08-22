<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\CollectionField;
use Pulsar\Extension\Form\Field\TextField;

final class CollectionFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsCollection(): void
    {
        $field = new CollectionField('tags', 'Tags', fn() => new TextField('tag'));

        self::assertSame('collection', $field->getType());
    }

    #[Test]
    public function addEntryCreatesNewChild(): void
    {
        $field = new CollectionField('tags', 'Tags', fn() => new TextField('tag'));
        $entry = $field->addEntry();

        self::assertCount(1, $field->getEntries());
        self::assertSame($entry, $field->getEntries()[0]);
    }

    #[Test]
    public function removeEntryRemovesByIndex(): void
    {
        $field = new CollectionField('tags', 'Tags', fn() => new TextField('tag'));
        $field->addEntry();
        $field->addEntry();
        $field->removeEntry(0);

        self::assertCount(1, $field->getEntries());
    }

    #[Test]
    public function setValuePopulatesEntries(): void
    {
        $field = new CollectionField('tags', 'Tags', fn() => new TextField('tag'));
        $field->setValue(['foo', 'bar', 'baz']);

        self::assertCount(3, $field->getEntries());
        self::assertSame('foo', $field->getEntries()[0]->getValue());
        self::assertSame('bar', $field->getEntries()[1]->getValue());
    }

    #[Test]
    public function isRequiredDependsOnMinEntries(): void
    {
        $optional = new CollectionField('tags', 'Tags', fn() => new TextField('tag'), minEntries: 0);
        $required = new CollectionField('tags', 'Tags', fn() => new TextField('tag'), minEntries: 1);

        self::assertFalse($optional->isRequired());
        self::assertTrue($required->isRequired());
    }

    #[Test]
    public function minAndMaxEntriesReturnConstructorValues(): void
    {
        $field = new CollectionField('tags', 'Tags', fn() => new TextField('tag'), minEntries: 1, maxEntries: 5);

        self::assertSame(1, $field->minEntries);
        self::assertSame(5, $field->maxEntries);
    }

    #[Test]
    public function idAndErrorIdUseCollectionPrefix(): void
    {
        $field = new CollectionField('phones', 'Phones', fn() => new TextField('phone'));

        self::assertSame('collection-phones', $field->getId());
        self::assertSame('collection-phones-error', $field->getErrorId());
    }
}
