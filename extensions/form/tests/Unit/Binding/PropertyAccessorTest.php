<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Binding;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Binding\PropertyAccessor;

final class PropertyAccessorTest extends TestCase
{
    #[Test]
    public function readSimpleProperty(): void
    {
        $accessor = new PropertyAccessor();
        $dto = new class {
            public string $name = 'John';
        };

        self::assertSame('John', $accessor->read($dto, 'name'));
    }

    #[Test]
    public function readReturnsNullForMissingProperty(): void
    {
        $accessor = new PropertyAccessor();
        $dto = new class {
            public string $name = 'John';
        };

        self::assertNull($accessor->read($dto, 'missing'));
    }

    #[Test]
    public function readDotNotationPath(): void
    {
        $accessor = new PropertyAccessor();
        $inner = new class {
            public string $city = 'Paris';
        };
        $dto = new class {
            public object $address;
        };
        $dto->address = $inner;

        self::assertSame('Paris', $accessor->read($dto, 'address.city'));
    }

    #[Test]
    public function writeSimpleProperty(): void
    {
        $accessor = new PropertyAccessor();
        $dto = new class {
            public string $name = '';
        };

        $accessor->write($dto, 'name', 'Jane');
        self::assertSame('Jane', $dto->name);
    }

    #[Test]
    public function writeIgnoresMissingProperty(): void
    {
        $accessor = new PropertyAccessor();
        $dto = new class {
            public string $name = 'John';
        };

        $accessor->write($dto, 'missing', 'value');
        self::assertSame('John', $dto->name);
    }

    #[Test]
    public function writeDotNotationPath(): void
    {
        $accessor = new PropertyAccessor();
        $inner = new class {
            public string $city = '';
        };
        $dto = new class {
            public object $address;
        };
        $dto->address = $inner;

        $accessor->write($dto, 'address.city', 'London');
        self::assertSame('London', $inner->city);
    }
}
