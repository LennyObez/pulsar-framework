<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Binding\PropertyAccessor;

#[CoversClass(PropertyAccessor::class)]
final class PropertyAccessorTest extends TestCase
{
    private PropertyAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new PropertyAccessor();
    }

    #[Test]
    public function readsSimpleProperty(): void
    {
        $dto = new class {
            public string $name = 'John';
        };

        self::assertSame('John', $this->accessor->read($dto, 'name'));
    }

    #[Test]
    public function writesSimpleProperty(): void
    {
        $dto = new class {
            public string $name = '';
        };

        $this->accessor->write($dto, 'name', 'Jane');
        self::assertSame('Jane', $dto->name);
    }

    #[Test]
    public function readsNestedProperty(): void
    {
        $dto = new class {
            public object $address;

            public function __construct()
            {
                $this->address = new class {
                    public string $city = 'New York';
                };
            }
        };

        self::assertSame('New York', $this->accessor->read($dto, 'address.city'));
    }

    #[Test]
    public function writesNestedProperty(): void
    {
        $dto = new class {
            public object $address;

            public function __construct()
            {
                $this->address = new class {
                    public string $city = '';
                };
            }
        };

        $this->accessor->write($dto, 'address.city', 'Boston');
        /** @var object{city: string} $address */
        $address = $dto->address;
        self::assertSame('Boston', $address->city);
    }

    #[Test]
    public function readReturnsNullForMissingProperty(): void
    {
        $dto = new class {
            public string $name = 'Test';
        };

        self::assertNull($this->accessor->read($dto, 'nonexistent'));
    }

    #[Test]
    public function readReturnsNullForMissingNestedProperty(): void
    {
        $dto = new class {
            public string $name = 'Test';
        };

        self::assertNull($this->accessor->read($dto, 'address.city'));
    }

    #[Test]
    public function writeDoesNothingForMissingProperty(): void
    {
        $dto = new class {
            public string $name = 'Original';
        };

        $this->accessor->write($dto, 'nonexistent', 'value');
        self::assertSame('Original', $dto->name);
    }

    #[Test]
    public function writeDoesNothingForMissingNestedPath(): void
    {
        $dto = new class {
            public string $name = 'Original';
        };

        $this->accessor->write($dto, 'address.city', 'Boston');
        self::assertSame('Original', $dto->name);
    }
}
