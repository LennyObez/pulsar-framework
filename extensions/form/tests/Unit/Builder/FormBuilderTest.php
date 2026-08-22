<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Field\FileField;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Http\Validation\Validator;

final class FormBuilderTest extends TestCase
{
    #[Test]
    public function buildCreatesFormWithFields(): void
    {
        $builder = $this->createBuilder();
        $form = $builder
            ->id('register')
            ->action('/register')
            ->method('POST')
            ->add(new TextField('name', 'Name'))
            ->build();

        self::assertSame('register', $form->getId());
        self::assertSame('/register', $form->getAction());
        self::assertSame('POST', $form->getMethod());
        self::assertTrue($form->hasField('name'));
    }

    #[Test]
    public function buildThrowsWithoutId(): void
    {
        $builder = $this->createBuilder();

        $this->expectException(FormException::class);
        $builder->build();
    }

    #[Test]
    public function removeFieldRemovesFromBuilder(): void
    {
        $builder = $this->createBuilder();
        $builder->id('test')
            ->add(new TextField('name', 'Name'))
            ->remove('name');

        $form = $builder->build();
        self::assertFalse($form->hasField('name'));
    }

    #[Test]
    public function getFieldReturnsAddedField(): void
    {
        $builder = $this->createBuilder();
        $field = new TextField('name', 'Name');
        $builder->add($field);

        self::assertSame($field, $builder->get('name'));
    }

    #[Test]
    public function getFieldThrowsOnMissing(): void
    {
        $builder = $this->createBuilder();

        $this->expectException(FormException::class);
        $builder->get('missing');
    }

    #[Test]
    public function csrfCanBeDisabled(): void
    {
        $builder = $this->createBuilder();
        $form = $builder->id('test')->csrf(false)->build();

        self::assertFalse($form->isCsrfEnabled());
    }

    #[Test]
    public function regulatedPresetWithFileFieldRequiresAntivirus(): void
    {
        $config = FormConfig::fromArray([
            'upload' => ['regulated_preset' => true],
        ]);
        $builder = new FormBuilder($config, new Validator());

        $builder->id('upload-form')->add(new FileField('doc'));

        $this->expectException(UploadException::class);
        $builder->build();
    }

    #[Test]
    public function regulatedPresetWithAntivirusAndFileFieldBuildsSuccessfully(): void
    {
        $config = FormConfig::fromArray([
            'upload' => ['regulated_preset' => true],
        ]);
        $antivirus = $this->createStub(AntivirusPort::class);
        $builder = new FormBuilder($config, new Validator(), antivirusPort: $antivirus);

        $form = $builder->id('upload-form')->add(new FileField('doc'))->build();

        self::assertTrue($form->hasField('doc'));
    }

    private function createBuilder(): FormBuilder
    {
        return new FormBuilder(
            FormConfig::fromArray([]),
            new Validator(),
        );
    }
}
