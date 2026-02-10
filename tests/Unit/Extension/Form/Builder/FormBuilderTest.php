<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\Form;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Field\FileField;
use Pulsar\Extension\Form\Field\PasswordField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Http\Validation\Validator;

#[CoversClass(FormBuilder::class)]
#[CoversClass(Form::class)]
final class FormBuilderTest extends TestCase
{
    private FormBuilder $builder;

    protected function setUp(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $this->builder = new FormBuilder($config, new Validator());
    }

    #[Test]
    public function it_builds_a_simple_form(): void
    {
        $form = $this->builder
            ->id('login')
            ->action('/login')
            ->method('POST')
            ->add(new TextField('username', 'Username'))
            ->add(new PasswordField('password', 'Password'))
            ->build();

        self::assertSame('login', $form->getId());
        self::assertSame('/login', $form->getAction());
        self::assertSame('POST', $form->getMethod());
        self::assertCount(2, $form->getFields());
        self::assertTrue($form->hasField('username'));
        self::assertTrue($form->hasField('password'));
        self::assertFalse($form->hasField('nonexistent'));
    }

    #[Test]
    public function it_throws_when_id_is_missing(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('Form ID is required');

        $this->builder
            ->action('/submit')
            ->build();
    }

    #[Test]
    public function it_removes_fields(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('a', 'A'))
            ->add(new TextField('b', 'B'))
            ->remove('a')
            ->build();

        self::assertCount(1, $form->getFields());
        self::assertFalse($form->hasField('a'));
    }

    #[Test]
    public function it_gets_fields_by_name(): void
    {
        $this->builder->add(new TextField('name', 'Name'));

        $field = $this->builder->get('name');
        self::assertSame('name', $field->getName());
    }

    #[Test]
    public function it_throws_getting_nonexistent_field(): void
    {
        $this->expectException(FormException::class);
        $this->builder->get('missing');
    }

    #[Test]
    public function it_requires_antivirus_for_file_uploads_in_regulated_preset(): void
    {
        $config = FormConfig::fromArray([
            'csrf' => ['enabled' => false],
            'upload' => ['regulated_preset' => true],
        ]);
        $builder = new FormBuilder($config, new Validator());

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('AntivirusPort must be configured');

        $builder
            ->id('upload-form')
            ->add(new FileField('document', 'Document'))
            ->build();
    }

    #[Test]
    public function form_submits_and_populates_fields(): void
    {
        $form = $this->builder
            ->id('contact')
            ->add(new TextField('name', 'Name'))
            ->add(new TextField('email', 'Email'))
            ->build();

        self::assertFalse($form->isSubmitted());

        $form->submit(['name' => 'John', 'email' => 'john@example.com']);

        self::assertTrue($form->isSubmitted());
        self::assertSame('John', $form->getField('name')->getValue());
        self::assertSame('john@example.com', $form->getField('email')->getValue());
    }

    #[Test]
    public function form_throws_on_double_submit(): void
    {
        $form = $this->builder->id('test')->add(new TextField('x', 'X'))->build();
        $form->submit(['x' => 'val']);

        $this->expectException(FormException::class);
        $this->expectExceptionMessage('already been submitted');
        $form->submit(['x' => 'val2']);
    }

    #[Test]
    public function form_validates_submitted_data(): void
    {
        $field = new TextField('name', 'Name');
        $field->setRequired(true);
        $field->setRules([new \Pulsar\Http\Validation\Rule\Required()]);

        $form = $this->builder->id('test')->add($field)->build();
        $form->submit(['name' => '']);

        $result = $form->validate();
        self::assertTrue($result->failed());
    }

    #[Test]
    public function form_passes_validation_with_valid_data(): void
    {
        $field = new TextField('name', 'Name');
        $field->setRules([new \Pulsar\Http\Validation\Rule\Required()]);

        $form = $this->builder->id('test')->add($field)->build();
        $form->submit(['name' => 'John']);

        self::assertTrue($form->isValid());
    }

    #[Test]
    public function form_get_data_returns_field_values(): void
    {
        $form = $this->builder
            ->id('test')
            ->add(new TextField('a', 'A'))
            ->add(new TextField('b', 'B'))
            ->build();

        $form->submit(['a' => 'val1', 'b' => 'val2']);
        $data = $form->getData();

        self::assertSame('val1', $data['a']);
        self::assertSame('val2', $data['b']);
    }

    #[Test]
    public function form_throws_on_get_data_before_submit(): void
    {
        $form = $this->builder->id('test')->add(new TextField('x', 'X'))->build();

        $this->expectException(FormException::class);
        $form->getData();
    }

    #[Test]
    public function form_throws_getting_nonexistent_field(): void
    {
        $form = $this->builder->id('test')->build();

        $this->expectException(FormException::class);
        $form->getField('missing');
    }
}
