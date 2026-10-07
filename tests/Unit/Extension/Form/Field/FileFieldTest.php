<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\FileField;

#[CoversClass(FileField::class)]
final class FileFieldTest extends TestCase
{
    #[Test]
    public function typeIsFile(): void
    {
        $field = new FileField('upload');
        self::assertSame('file', $field->getType());
    }

    #[Test]
    public function defaultsToEmptyMimeTypesAndNullMaxSize(): void
    {
        $field = new FileField('doc');

        self::assertSame([], $field->getAllowedMimeTypes());
        self::assertNull($field->getMaxSize());
        self::assertFalse($field->isMultiple());
        self::assertNull($field->getAccept());
    }

    #[Test]
    public function allowedMimeTypesAreConfigurable(): void
    {
        $field = new FileField('avatar', 'Avatar', ['image/jpeg', 'image/png']);
        self::assertSame(['image/jpeg', 'image/png'], $field->getAllowedMimeTypes());
    }

    #[Test]
    public function maxSizeIsConfigurable(): void
    {
        $field = new FileField('doc', 'Document', maxSize: 2_097_152);
        self::assertSame(2_097_152, $field->getMaxSize());
    }

    #[Test]
    public function multipleIsConfigurable(): void
    {
        $field = new FileField('photos', 'Photos', multiple: true);
        self::assertTrue($field->isMultiple());
    }

    #[Test]
    public function acceptAttributeIsConfigurable(): void
    {
        $field = new FileField('image', 'Image', accept: 'image/*');
        self::assertSame('image/*', $field->getAccept());
    }

    #[Test]
    public function fullConstructor(): void
    {
        $field = new FileField(
            name: 'attachment',
            label: 'Attachment',
            allowedMimeTypes: ['application/pdf'],
            maxSize: 5_242_880,
            multiple: true,
            accept: '.pdf',
        );

        self::assertSame('attachment', $field->getName());
        self::assertSame('Attachment', $field->getLabel());
        self::assertSame(['application/pdf'], $field->getAllowedMimeTypes());
        self::assertSame(5_242_880, $field->getMaxSize());
        self::assertTrue($field->isMultiple());
        self::assertSame('.pdf', $field->getAccept());
    }
}
