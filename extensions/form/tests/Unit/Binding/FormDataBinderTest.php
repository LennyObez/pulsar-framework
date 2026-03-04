<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Binding;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Binding\FormDataBinder;
use Pulsar\Extension\Form\Binding\PropertyAccessor;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Extension\Form\Contract\FormInterface;

final class FormDataBinderTest extends TestCase
{
    private FormDataBinder $binder;
    private PropertyAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new PropertyAccessor();
        $this->binder = new FormDataBinder($this->accessor);
    }

    #[Test]
    public function hydrateCreatesPopulatedDto(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getData')->willReturn([
            'name' => 'John',
            'age' => '30',
        ]);

        $dto = $this->binder->hydrate($form, TestDto::class);

        self::assertInstanceOf(TestDto::class, $dto);
        self::assertSame('John', $dto->name);
        self::assertSame(30, $dto->age);
    }

    #[Test]
    public function hydrateSkipsNonexistentProperties(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getData')->willReturn([
            'name' => 'Jane',
            'nonexistent' => 'value',
        ]);

        $dto = $this->binder->hydrate($form, TestDto::class);

        self::assertSame('Jane', $dto->name);
    }

    #[Test]
    public function hydrateHandlesEmptyData(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('getData')->willReturn([]);

        $dto = $this->binder->hydrate($form, TestDto::class);

        self::assertInstanceOf(TestDto::class, $dto);
    }

    #[Test]
    public function populateSetsFieldValues(): void
    {
        $field = $this->createMock(FieldInterface::class);
        $field->expects(self::once())->method('setValue')->with('Alice');

        $form = $this->createStub(FormInterface::class);
        $form->method('getFields')->willReturn(['name' => $field]);

        $dto = new TestDto();
        $dto->name = 'Alice';

        $this->binder->populate($form, $dto);
    }
}

/**
 * @internal Test fixture only
 */
class TestDto
{
    public string $name = '';
    public int $age = 0;
    public bool $active = false;
}
