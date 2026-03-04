<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveForm;

/**
 * Edge case tests for LiveForm validation rules.
 */
#[CoversClass(LiveForm::class)]
final class LiveFormEdgeTest extends TestCase
{
    #[Test]
    public function validateRequiredWithNullValue(): void
    {
        $form = new NullableFieldForm();
        $form->value = null;

        self::assertFalse($form->validate());
        self::assertStringContainsString('required', $form->fieldErrors('value')[0]);
    }

    #[Test]
    public function validateRequiredWithEmptyArray(): void
    {
        $form = new ArrayFieldForm();
        $form->items = [];

        self::assertFalse($form->validate());
        self::assertStringContainsString('required', $form->fieldErrors('items')[0]);
    }

    #[Test]
    public function validateEmailPassesForEmptyString(): void
    {
        $form = new OptionalEmailForm();
        $form->email = '';

        // Empty email should pass the email rule (it's the 'required' rule that catches blanks)
        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateUrlPassesForEmptyString(): void
    {
        $form = new OptionalUrlForm();
        $form->website = '';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateUrlPassesForValidUrl(): void
    {
        $form = new OptionalUrlForm();
        $form->website = 'https://example.com';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateNumericPassesForEmptyString(): void
    {
        $form = new OptionalNumericForm();
        $form->value = '';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateNumericPassesForNull(): void
    {
        $form = new NullableNumericForm();
        $form->value = null;

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateNumericPassesForValidNumber(): void
    {
        $form = new OptionalNumericForm();
        $form->value = '42.5';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateMinWithNoParameterUsesZero(): void
    {
        $form = new MinNoParamForm();
        $form->text = 'any';

        // min:0 means anything with length >= 0 passes (always)
        self::assertTrue($form->validate());
    }

    #[Test]
    public function validateMaxWithNoParameterUsesZero(): void
    {
        $form = new MaxNoParamForm();
        $form->text = 'a';

        // max:0 means any string with length > 0 fails
        self::assertFalse($form->validate());
    }

    #[Test]
    public function validateConfirmedWithMissingConfirmationField(): void
    {
        $form = new NoConfirmationFieldForm();
        $form->password = 'secret';

        // No password_confirmation property exists, so confirmed rule passes
        self::assertTrue($form->validate());
    }

    #[Test]
    public function resetClearsErrorsAndResetsFields(): void
    {
        $form = new ResetTestForm();
        $form->name = 'Changed';
        $form->addError('name', 'Some error');

        self::assertTrue($form->hasErrors());

        $form->reset();

        self::assertFalse($form->hasErrors());
        self::assertSame('', $form->name);
    }

    #[Test]
    public function toArrayReturnsOnlyRuleFields(): void
    {
        $form = new ResetTestForm();
        $form->name = 'Test';

        $data = $form->toArray();

        self::assertSame(['name' => 'Test'], $data);
    }

    #[Test]
    public function fillIgnoresNonexistentProperties(): void
    {
        $form = new ResetTestForm();
        $form->fill(['name' => 'Filled', 'ghost' => 'ignored']);

        self::assertSame('Filled', $form->name);
    }

    #[Test]
    public function validateRuleForNonExistentPropertyIsSkipped(): void
    {
        $form = new GhostPropertyForm();

        // Rule references 'ghost' property which doesn't exist
        self::assertTrue($form->validate());
    }

    #[Test]
    public function multipleValidateCallsClearPreviousErrors(): void
    {
        $form = new ResetTestForm();
        $form->name = '';
        $form->validate();
        self::assertTrue($form->hasErrors());

        $form->name = 'Valid';
        $form->validate();
        self::assertFalse($form->hasErrors());
    }
}

/** @internal */
final class NullableFieldForm extends LiveForm
{
    public mixed $value = null;

    public function rules(): array
    {
        return ['value' => ['required']];
    }
}

/** @internal */
final class ArrayFieldForm extends LiveForm
{
    /** @var list<string> */
    public array $items = [];

    public function rules(): array
    {
        return ['items' => ['required']];
    }
}

/** @internal */
final class OptionalEmailForm extends LiveForm
{
    public string $email = '';

    public function rules(): array
    {
        return ['email' => ['email']];
    }
}

/** @internal */
final class OptionalUrlForm extends LiveForm
{
    public string $website = '';

    public function rules(): array
    {
        return ['website' => ['url']];
    }
}

/** @internal */
final class OptionalNumericForm extends LiveForm
{
    public string $value = '';

    public function rules(): array
    {
        return ['value' => ['numeric']];
    }
}

/** @internal */
final class NullableNumericForm extends LiveForm
{
    public ?string $value = null;

    public function rules(): array
    {
        return ['value' => ['numeric']];
    }
}

/** @internal */
final class MinNoParamForm extends LiveForm
{
    public string $text = '';

    public function rules(): array
    {
        return ['text' => ['min']];
    }
}

/** @internal */
final class MaxNoParamForm extends LiveForm
{
    public string $text = '';

    public function rules(): array
    {
        return ['text' => ['max']];
    }
}

/** @internal */
final class NoConfirmationFieldForm extends LiveForm
{
    public string $password = '';

    public function rules(): array
    {
        return ['password' => ['confirmed']];
    }
}

/** @internal */
final class ResetTestForm extends LiveForm
{
    public string $name = '';

    public function rules(): array
    {
        return ['name' => ['required']];
    }
}

/** @internal */
final class GhostPropertyForm extends LiveForm
{
    public function rules(): array
    {
        return ['ghost' => ['required']];
    }
}
