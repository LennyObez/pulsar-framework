<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\Form;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Validator;

final class FormTest extends TestCase
{
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $form = $this->createForm();

        self::assertSame('login', $form->getId());
        self::assertSame('POST', $form->getMethod());
        self::assertSame('/login', $form->getAction());
        self::assertTrue($form->isCsrfEnabled());
    }

    #[Test]
    public function getFieldReturnsNamedField(): void
    {
        $form = $this->createForm();

        self::assertSame('username', $form->getField('username')->getName());
    }

    #[Test]
    public function getFieldThrowsOnMissingField(): void
    {
        $form = $this->createForm();

        $this->expectException(FormException::class);
        $form->getField('nonexistent');
    }

    #[Test]
    public function hasFieldReturnsTrueForExistingField(): void
    {
        $form = $this->createForm();

        self::assertTrue($form->hasField('username'));
        self::assertFalse($form->hasField('nonexistent'));
    }

    #[Test]
    public function submitPopulatesFieldValues(): void
    {
        $form = $this->createForm();
        $form->submit(['username' => 'john']);

        self::assertTrue($form->isSubmitted());
        self::assertSame('john', $form->getField('username')->getValue());
    }

    #[Test]
    public function submitThrowsWhenAlreadySubmitted(): void
    {
        $form = $this->createForm();
        $form->submit(['username' => 'john']);

        $this->expectException(FormException::class);
        $form->submit(['username' => 'jane']);
    }

    #[Test]
    public function getDataThrowsBeforeSubmission(): void
    {
        $form = $this->createForm();

        $this->expectException(FormException::class);
        $form->getData();
    }

    #[Test]
    public function getDataReturnsFieldValues(): void
    {
        $form = $this->createForm();
        $form->submit(['username' => 'john']);

        $data = $form->getData();
        self::assertSame('john', $data['username']);
    }

    #[Test]
    public function validateThrowsBeforeSubmission(): void
    {
        $validator = new Validator();
        $form = new Form('test', 'POST', '/test', [], false, $validator);

        $this->expectException(FormException::class);
        $form->validate();
    }

    #[Test]
    public function validateReturnsValidationResult(): void
    {
        $form = $this->createForm();
        $form->submit(['username' => 'john']);

        $result = $form->validate();
        self::assertInstanceOf(ValidationResult::class, $result);
    }

    #[Test]
    public function getValidationResultThrowsBeforeValidation(): void
    {
        $form = $this->createForm();

        $this->expectException(FormException::class);
        $form->getValidationResult();
    }

    #[Test]
    public function csrfTokenAndFieldName(): void
    {
        $form = new Form(
            id: 'test',
            method: 'POST',
            action: '/test',
            fields: [],
            csrfEnabled: true,
            validator: new Validator(),
            csrfToken: 'token-abc',
            csrfFieldName: '_custom_csrf',
        );

        self::assertSame('token-abc', $form->getCsrfToken());
        self::assertSame('_custom_csrf', $form->getCsrfFieldName());
    }

    private function createForm(): Form
    {
        return new Form(
            id: 'login',
            method: 'POST',
            action: '/login',
            fields: ['username' => new TextField('username', 'Username')],
            csrfEnabled: true,
            validator: new Validator(),
        );
    }
}
