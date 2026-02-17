<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveForm;

#[CoversClass(LiveForm::class)]
final class LiveFormTest extends TestCase
{
    #[Test]
    public function validatePassesWhenAllRulesSatisfied(): void
    {
        $form = new ConcreteTestForm();
        $form->email = 'test@example.com';
        $form->name = 'John';

        self::assertTrue($form->validate());
        self::assertFalse($form->hasErrors());
        self::assertSame([], $form->errors());
    }

    #[Test]
    public function validateFailsForRequiredEmptyFields(): void
    {
        $form = new ConcreteTestForm();
        $form->email = '';
        $form->name = '';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasErrors());
        self::assertTrue($form->hasFieldError('email'));
        self::assertTrue($form->hasFieldError('name'));
    }

    #[Test]
    public function validateReturnsEmailError(): void
    {
        $form = new ConcreteTestForm();
        $form->email = 'not-an-email';
        $form->name = 'John';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('email'));
        self::assertStringContainsString('valid email', $form->fieldErrors('email')[0]);
    }

    #[Test]
    public function validateMinLengthRule(): void
    {
        $form = new ConcreteTestForm();
        $form->email = 'a@b.com';
        $form->name = 'J';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('name'));
        self::assertStringContainsString('at least 2', $form->fieldErrors('name')[0]);
    }

    #[Test]
    public function validateMaxLengthRule(): void
    {
        $form = new MaxLengthTestForm();
        $form->code = 'ABCDEF';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('code'));
    }

    #[Test]
    public function validateConfirmedRule(): void
    {
        $form = new ConfirmedTestForm();
        $form->password = 'secret123';
        $form->password_confirmation = 'different';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('password'));
        self::assertStringContainsString('confirmation does not match', $form->fieldErrors('password')[0]);
    }

    #[Test]
    public function validateConfirmedPasses(): void
    {
        $form = new ConfirmedTestForm();
        $form->password = 'secret123';
        $form->password_confirmation = 'secret123';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateNumericRule(): void
    {
        $form = new NumericTestForm();
        $form->age = 'not-a-number';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('age'));
    }

    #[Test]
    public function validateUrlRule(): void
    {
        $form = new UrlTestForm();
        $form->website = 'not-a-url';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('website'));
    }

    #[Test]
    public function resetClearsFieldsAndErrors(): void
    {
        $form = new ConcreteTestForm();
        $form->email = 'test@test.com';
        $form->name = 'John';
        $form->validate();

        $form->email = '';
        $form->validate();
        self::assertTrue($form->hasErrors());

        $form->reset();
        self::assertSame('', $form->email);
        self::assertSame('', $form->name);
        self::assertFalse($form->hasErrors());
    }

    #[Test]
    public function fillPopulatesFromArray(): void
    {
        $form = new ConcreteTestForm();
        $form->fill(['email' => 'filled@test.com', 'name' => 'Filled']);

        self::assertSame('filled@test.com', $form->email);
        self::assertSame('Filled', $form->name);
    }

    #[Test]
    public function fillIgnoresUnknownKeys(): void
    {
        $form = new ConcreteTestForm();
        $form->fill(['unknown' => 'value', 'email' => 'a@b.com']);

        self::assertSame('a@b.com', $form->email);
    }

    #[Test]
    public function toArrayReturnsFormData(): void
    {
        $form = new ConcreteTestForm();
        $form->email = 'data@test.com';
        $form->name = 'Data';

        self::assertSame([
            'email' => 'data@test.com',
            'name' => 'Data',
        ], $form->toArray());
    }

    #[Test]
    public function addErrorAddsManualError(): void
    {
        $form = new ConcreteTestForm();
        $form->addError('email', 'Email is already taken.');

        self::assertTrue($form->hasErrors());
        self::assertTrue($form->hasFieldError('email'));
        self::assertSame(['Email is already taken.'], $form->fieldErrors('email'));
    }

    #[Test]
    public function fieldErrorsReturnsEmptyForValidField(): void
    {
        $form = new ConcreteTestForm();

        self::assertSame([], $form->fieldErrors('nonexistent'));
    }

    #[Test]
    public function hasFieldErrorReturnsFalseForCleanField(): void
    {
        $form = new ConcreteTestForm();
        $form->email = 'valid@test.com';
        $form->name = 'Test';
        $form->validate();

        self::assertFalse($form->hasFieldError('email'));
    }

    #[Test]
    public function multipleErrorsOnSameField(): void
    {
        $form = new MultiRuleTestForm();
        $form->username = '';

        $form->validate();

        self::assertCount(2, $form->fieldErrors('username'));
    }

    #[Test]
    public function unknownRuleIsSkipped(): void
    {
        $form = new UnknownRuleTestForm();
        $form->field = 'value';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateMinWithNumericValue(): void
    {
        $form = new NumericMinTestForm();
        $form->count = '0';

        self::assertFalse($form->validate());
        self::assertStringContainsString('at least 1', $form->fieldErrors('count')[0]);
    }

    #[Test]
    public function validateMaxWithNumericValue(): void
    {
        $form = new NumericMaxTestForm();
        $form->count = '200';

        self::assertFalse($form->validate());
        self::assertStringContainsString('must not exceed 100', $form->fieldErrors('count')[0]);
    }
}

/** @internal */
final class ConcreteTestForm extends LiveForm
{
    public string $email = '';
    public string $name = '';

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'name' => ['required', 'min:2'],
        ];
    }
}

/** @internal */
final class MaxLengthTestForm extends LiveForm
{
    public string $code = '';

    public function rules(): array
    {
        return ['code' => ['max:5']];
    }
}

/** @internal */
final class ConfirmedTestForm extends LiveForm
{
    public string $password = '';
    public string $password_confirmation = '';

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed'],
            'password_confirmation' => ['required'],
        ];
    }
}

/** @internal */
final class NumericTestForm extends LiveForm
{
    public string $age = '';

    public function rules(): array
    {
        return ['age' => ['numeric']];
    }
}

/** @internal */
final class UrlTestForm extends LiveForm
{
    public string $website = '';

    public function rules(): array
    {
        return ['website' => ['url']];
    }
}

/** @internal */
final class MultiRuleTestForm extends LiveForm
{
    public string $username = '';

    public function rules(): array
    {
        return ['username' => ['required', 'min:3']];
    }
}

/** @internal */
final class UnknownRuleTestForm extends LiveForm
{
    public string $field = '';

    public function rules(): array
    {
        return ['field' => ['custom_rule_xyz']];
    }
}

/** @internal */
final class NumericMinTestForm extends LiveForm
{
    public string $count = '';

    public function rules(): array
    {
        return ['count' => ['min:1']];
    }
}

/** @internal */
final class NumericMaxTestForm extends LiveForm
{
    public string $count = '';

    public function rules(): array
    {
        return ['count' => ['max:100']];
    }
}
