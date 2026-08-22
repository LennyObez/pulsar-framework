<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Binding\FormDataBinder;
use Pulsar\Extension\Form\Binding\PropertyAccessor;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Field\NumberField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Http\Validation\Validator;

#[CoversClass(FormDataBinder::class)]
final class FormDataBinderDeepTest extends TestCase
{
    private FormDataBinder $binder;
    private FormBuilder $builder;

    protected function setUp(): void
    {
        $this->binder = new FormDataBinder(new PropertyAccessor());
        $formConfig = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $this->builder = new FormBuilder($formConfig, new Validator());
    }

    #[Test]
    public function hydrateCoercesIntField(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new NumberField('age', 'Age'))
            ->build();

        $form->submit(['age' => '25']);

        $dto = $this->binder->hydrate($form, TestIntDto::class);

        self::assertSame(25, $dto->age);
    }

    #[Test]
    public function hydrateCoercesBoolField(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('active', 'Active'))
            ->build();

        $form->submit(['active' => '1']);

        $dto = $this->binder->hydrate($form, TestBoolDto::class);

        self::assertTrue($dto->active);
    }

    #[Test]
    public function hydrateCoercesFloatField(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('price', 'Price'))
            ->build();

        $form->submit(['price' => '9.99']);

        $dto = $this->binder->hydrate($form, TestFloatDto::class);

        self::assertSame(9.99, $dto->price);
    }

    #[Test]
    public function hydrateSkipsNonExistentProperties(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('nonexistent', 'Non'))
            ->build();

        $form->submit(['nonexistent' => 'value']);

        $dto = $this->binder->hydrate($form, TestStringDto::class);

        self::assertSame('', $dto->name);
    }

    #[Test]
    public function hydrateHandlesNullForNullableType(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('name', 'Name'))
            ->build();

        $form->submit(['name' => null]);

        $dto = $this->binder->hydrate($form, TestNullableDto::class);

        self::assertNull($dto->name);
    }

    #[Test]
    public function hydrateHandlesNullForNonNullableType(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('name', 'Name'))
            ->build();

        $form->submit(['name' => null]);

        $dto = $this->binder->hydrate($form, TestStringDto::class);

        self::assertSame('', $dto->name);
    }

    #[Test]
    public function populatesFillsFieldsFromDto(): void
    {
        $nameField = new TextField('name', 'Name');
        $form = $this->builder
            ->id('test')
            ->add($nameField)
            ->build();

        $dto = new TestStringDto();
        $dto->name = 'Filled';

        $this->binder->populate($form, $dto);

        self::assertSame('Filled', $nameField->getValue());
    }

    #[Test]
    public function populateSkipsNullValues(): void
    {
        $nameField = new TextField('name', 'Name');
        $nameField->setValue('Original');

        $form = $this->builder
            ->id('test')
            ->add($nameField)
            ->build();

        $dto = new TestNullableDto();
        $dto->name = null;

        $this->binder->populate($form, $dto);

        self::assertSame('Original', $nameField->getValue());
    }

    #[Test]
    public function hydrateCoercesNonNumericToDefaultInt(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('age', 'Age'))
            ->build();

        $form->submit(['age' => 'not-a-number']);

        $dto = $this->binder->hydrate($form, TestIntDto::class);

        self::assertSame(0, $dto->age);
    }

    #[Test]
    public function hydrateCoercesNonNumericToDefaultFloat(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('price', 'Price'))
            ->build();

        $form->submit(['price' => 'not-a-float']);

        $dto = $this->binder->hydrate($form, TestFloatDto::class);

        self::assertSame(0.0, $dto->price);
    }
}

class TestStringDto
{
    public string $name = '';
}

class TestIntDto
{
    public int $age = 0;
}

class TestBoolDto
{
    public bool $active = false;
}

class TestFloatDto
{
    public float $price = 0.0;
}

class TestNullableDto
{
    public ?string $name = null;
}
