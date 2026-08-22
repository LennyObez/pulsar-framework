<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\Form;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Http\Validation\Validator;

#[CoversClass(Form::class)]
final class FormTest extends TestCase
{
    /**
     * @param array<string, \Pulsar\Extension\Form\Contract\FieldInterface> $fields
     */
    private function createForm(
        string $id = 'test-form',
        string $method = 'POST',
        string $action = '/submit',
        array $fields = [],
        bool $csrfEnabled = false,
        ?string $csrfToken = null,
        string $csrfFieldName = '_csrf_token',
    ): Form {
        return new Form(
            id: $id,
            method: $method,
            action: $action,
            fields: $fields,
            csrfEnabled: $csrfEnabled,
            validator: new Validator(),
            csrfToken: $csrfToken,
            csrfFieldName: $csrfFieldName,
        );
    }

    #[Test]
    public function getIdReturnsConfiguredId(): void
    {
        $form = $this->createForm(id: 'login');
        self::assertSame('login', $form->getId());
    }

    #[Test]
    public function getMethodReturnsConfiguredMethod(): void
    {
        $form = $this->createForm(method: 'PUT');
        self::assertSame('PUT', $form->getMethod());
    }

    #[Test]
    public function getActionReturnsConfiguredAction(): void
    {
        $form = $this->createForm(action: '/api/register');
        self::assertSame('/api/register', $form->getAction());
    }

    #[Test]
    public function getFieldsReturnsAllFields(): void
    {
        $name = new TextField('name', 'Name');
        $email = new TextField('email', 'Email');
        $form = $this->createForm(fields: ['name' => $name, 'email' => $email]);

        self::assertSame(['name' => $name, 'email' => $email], $form->getFields());
    }

    #[Test]
    public function getFieldReturnsFieldByName(): void
    {
        $name = new TextField('name', 'Name');
        $form = $this->createForm(fields: ['name' => $name]);

        self::assertSame($name, $form->getField('name'));
    }

    #[Test]
    public function getFieldThrowsForNonexistentField(): void
    {
        $form = $this->createForm();

        $this->expectException(FormException::class);
        $form->getField('nonexistent');
    }

    #[Test]
    public function hasFieldReturnsTrueForExistingField(): void
    {
        $form = $this->createForm(fields: ['name' => new TextField('name', 'Name')]);
        self::assertTrue($form->hasField('name'));
    }

    #[Test]
    public function hasFieldReturnsFalseForMissingField(): void
    {
        $form = $this->createForm();
        self::assertFalse($form->hasField('missing'));
    }

    #[Test]
    public function submitPopulatesFieldValues(): void
    {
        $field = new TextField('name', 'Name');
        $form = $this->createForm(fields: ['name' => $field]);

        $form->submit(['name' => 'Alice']);

        self::assertTrue($form->isSubmitted());
        self::assertSame('Alice', $field->getValue());
    }

    #[Test]
    public function submitIgnoresDataForMissingFields(): void
    {
        $field = new TextField('name', 'Name');
        $form = $this->createForm(fields: ['name' => $field]);

        $form->submit(['name' => 'Bob', 'unknown' => 'value']);

        self::assertSame('Bob', $field->getValue());
    }

    #[Test]
    public function submitThrowsIfAlreadySubmitted(): void
    {
        $form = $this->createForm();
        $form->submit([]);

        $this->expectException(FormException::class);
        $form->submit([]);
    }

    #[Test]
    public function isSubmittedReturnsFalseBeforeSubmission(): void
    {
        $form = $this->createForm();
        self::assertFalse($form->isSubmitted());
    }

    #[Test]
    public function validateThrowsIfNotSubmitted(): void
    {
        $form = $this->createForm();

        $this->expectException(FormException::class);
        $form->validate();
    }

    #[Test]
    public function validateReturnsValidationResult(): void
    {
        $form = $this->createForm(fields: ['name' => new TextField('name', 'Name')]);
        $form->submit(['name' => 'Alice']);

        $result = $form->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function isValidAutoTriggersValidation(): void
    {
        $form = $this->createForm(fields: ['name' => new TextField('name', 'Name')]);
        $form->submit(['name' => 'Alice']);

        self::assertTrue($form->isValid());
    }

    #[Test]
    public function getValidationResultThrowsBeforeValidation(): void
    {
        $form = $this->createForm();

        $this->expectException(FormException::class);
        $form->getValidationResult();
    }

    #[Test]
    public function getValidationResultReturnsResultAfterValidation(): void
    {
        $form = $this->createForm(fields: ['name' => new TextField('name', 'Name')]);
        $form->submit(['name' => 'test']);
        $result = $form->validate();

        self::assertSame($result, $form->getValidationResult());
    }

    #[Test]
    public function getDataThrowsIfNotSubmitted(): void
    {
        $form = $this->createForm(fields: ['name' => new TextField('name', 'Name')]);

        $this->expectException(FormException::class);
        $form->getData();
    }

    #[Test]
    public function getDataReturnsFieldValues(): void
    {
        $form = $this->createForm(fields: ['name' => new TextField('name', 'Name')]);
        $form->submit(['name' => 'Alice']);

        $data = $form->getData();

        self::assertSame(['name' => 'Alice'], $data);
    }

    #[Test]
    public function isCsrfEnabledReflectsConfig(): void
    {
        $formWithCsrf = $this->createForm(csrfEnabled: true);
        $formWithoutCsrf = $this->createForm(csrfEnabled: false);

        self::assertTrue($formWithCsrf->isCsrfEnabled());
        self::assertFalse($formWithoutCsrf->isCsrfEnabled());
    }

    #[Test]
    public function getCsrfTokenReturnsToken(): void
    {
        $form = $this->createForm(csrfToken: 'token123');
        self::assertSame('token123', $form->getCsrfToken());
    }

    #[Test]
    public function getCsrfTokenReturnsNullWhenNotSet(): void
    {
        $form = $this->createForm();
        self::assertNull($form->getCsrfToken());
    }

    #[Test]
    public function getCsrfFieldNameReturnsConfiguredName(): void
    {
        $form = $this->createForm(csrfFieldName: '_token');
        self::assertSame('_token', $form->getCsrfFieldName());
    }

    #[Test]
    public function getCsrfFieldNameDefaultsToStandard(): void
    {
        $form = $this->createForm();
        self::assertSame('_csrf_token', $form->getCsrfFieldName());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function submitDataProvider(): iterable
    {
        yield 'single field' => [['name' => 'Alice'], ['name' => 'Alice']];
        yield 'null value' => [['name' => null], ['name' => null]];
        yield 'integer value' => [['name' => 42], ['name' => 42]];
        yield 'multiple fields' => [['name' => 'A', 'email' => 'a@b'], ['name' => 'A', 'email' => 'a@b']];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('submitDataProvider')]
    public function submitHandlesVariousDataTypes(array $input, array $expected): void
    {
        $fields = [];

        foreach ($input as $key => $value) {
            $fields[$key] = new TextField($key, ucfirst($key));
        }

        $form = $this->createForm(fields: $fields);
        $form->submit($input);

        foreach ($expected as $key => $expectedValue) {
            self::assertSame($expectedValue, $form->getField($key)->getValue());
        }
    }

    #[Test]
    public function submitWithEmptyDataMarksAsSubmitted(): void
    {
        $form = $this->createForm();
        $form->submit([]);
        self::assertTrue($form->isSubmitted());
    }
}
