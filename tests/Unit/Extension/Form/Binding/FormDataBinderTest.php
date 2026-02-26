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
#[CoversClass(PropertyAccessor::class)]
final class FormDataBinderTest extends TestCase
{
    private FormDataBinder $binder;

    protected function setUp(): void
    {
        $this->binder = new FormDataBinder(new PropertyAccessor());
    }

    #[Test]
    public function it_hydrates_a_dto_from_form_data(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $builder = new FormBuilder($config, new Validator());

        $form = $builder
            ->id('test')
            ->add(new TextField('name', 'Name'))
            ->add(new NumberField('age', 'Age'))
            ->build();

        $form->submit(['name' => 'John', 'age' => '25']);

        $dto = $this->binder->hydrate($form, TestUserDto::class);

        self::assertSame('John', $dto->name);
        self::assertSame(25, $dto->age);
    }

    #[Test]
    public function it_populates_form_from_dto(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $builder = new FormBuilder($config, new Validator());

        $form = $builder
            ->id('test')
            ->add(new TextField('name', 'Name'))
            ->add(new NumberField('age', 'Age'))
            ->build();

        $dto = new TestUserDto();
        $dto->name = 'Jane';
        $dto->age = 30;

        $this->binder->populate($form, $dto);

        self::assertSame('Jane', $form->getField('name')->getValue());
        self::assertSame(30, $form->getField('age')->getValue());
    }

    #[Test]
    public function property_accessor_reads_nested_paths(): void
    {
        $accessor = new PropertyAccessor();

        $parent = new TestParentDto();
        $parent->child = new TestChildDto();
        $parent->child->value = 'nested';

        self::assertSame('nested', $accessor->read($parent, 'child.value'));
    }

    #[Test]
    public function property_accessor_writes_nested_paths(): void
    {
        $accessor = new PropertyAccessor();

        $parent = new TestParentDto();
        $parent->child = new TestChildDto();

        $accessor->write($parent, 'child.value', 'written');

        self::assertNotNull($parent->child);
        self::assertSame('written', $parent->child->value);
    }

    #[Test]
    public function property_accessor_returns_null_for_missing_path(): void
    {
        $accessor = new PropertyAccessor();

        $dto = new TestUserDto();

        self::assertNull($accessor->read($dto, 'nonexistent'));
    }
}

class TestUserDto
{
    public string $name = '';
    public int $age = 0;
}

class TestParentDto
{
    public ?TestChildDto $child = null;
}

class TestChildDto
{
    public string $value = '';
}
