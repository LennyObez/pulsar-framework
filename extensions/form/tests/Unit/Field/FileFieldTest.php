<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\FileField;

final class FileFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsFile(): void
    {
        self::assertSame('file', new FileField('avatar')->getType());
    }

    #[Test]
    public function allowedMimeTypesReturnProvidedValues(): void
    {
        $field = new FileField('doc', allowedMimeTypes: ['application/pdf', 'image/png']);

        self::assertSame(['application/pdf', 'image/png'], $field->getAllowedMimeTypes());
    }

    #[Test]
    public function maxSizeReturnsProvidedValue(): void
    {
        $field = new FileField('doc', maxSize: 5_000_000);

        self::assertSame(5_000_000, $field->getMaxSize());
    }

    #[Test]
    public function multipleDefaultsToFalse(): void
    {
        self::assertFalse(new FileField('doc')->isMultiple());
    }

    #[Test]
    public function multipleCanBeEnabled(): void
    {
        self::assertTrue(new FileField('doc', multiple: true)->isMultiple());
    }

    #[Test]
    public function acceptReturnsProvidedValue(): void
    {
        $field = new FileField('doc', accept: '.pdf,.doc');

        self::assertSame('.pdf,.doc', $field->getAccept());
    }
}
