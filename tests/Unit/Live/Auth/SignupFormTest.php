<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Live\Auth\SignupForm;

#[CoversClass(SignupForm::class)]
final class SignupFormTest extends TestCase
{
    #[Test]
    public function validatesSuccessfully(): void
    {
        $form = new SignupForm();
        $form->name = 'John Doe';
        $form->email = 'john@example.com';
        $form->password = 'securepassword';
        $form->password_confirmation = 'securepassword';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function failsWithShortName(): void
    {
        $form = new SignupForm();
        $form->name = 'J';
        $form->email = 'j@example.com';
        $form->password = 'password123';
        $form->password_confirmation = 'password123';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('name'));
    }

    #[Test]
    public function failsWithPasswordMismatch(): void
    {
        $form = new SignupForm();
        $form->name = 'John';
        $form->email = 'john@example.com';
        $form->password = 'password123';
        $form->password_confirmation = 'different';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('password'));
    }

    #[Test]
    public function failsWithShortPassword(): void
    {
        $form = new SignupForm();
        $form->name = 'John';
        $form->email = 'john@example.com';
        $form->password = 'short';
        $form->password_confirmation = 'short';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('password'));
    }

    /**
     * The rule used to read `min:8` whatever the surface had resolved and
     * displayed, so an operator configuring 12 got 8.
     */
    #[Test]
    public function enforcesTheAssignedMinimumRatherThanACompiledInOne(): void
    {
        $form = new SignupForm();
        $form->minPasswordLength = 12;
        $form->name = 'John Doe';
        $form->email = 'john@example.com';
        $form->password = 'elevenchars';
        $form->password_confirmation = 'elevenchars';

        self::assertFalse($form->validate());
        self::assertContains('password must be at least 12 characters.', $form->fieldErrors('password'));

        $form->password = 'twelvechars!';
        $form->password_confirmation = 'twelvechars!';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function minimumRuleTracksTheAssignedLength(): void
    {
        $form = new SignupForm();
        $form->minPasswordLength = 16;

        self::assertContains('min_length:16', $form->rules()['password']);
    }

    /**
     * `LiveForm::fill()` assigns any property that exists, request data included.
     */
    #[Test]
    public function refusesToBeWeakenedBelowTheFrameworkFloor(): void
    {
        $form = new SignupForm();
        $form->fill(['minPasswordLength' => 1]);
        $form->name = 'John Doe';
        $form->email = 'john@example.com';
        $form->password = 'sevench';
        $form->password_confirmation = 'sevench';

        self::assertFalse($form->validate());
        self::assertContains(
            'password must be at least ' . PasswordHasherInterface::MIN_LENGTH . ' characters.',
            $form->fieldErrors('password'),
        );
    }

    #[Test]
    public function rejectsAPasswordLongerThanTheHasherAccepts(): void
    {
        $tooLong = str_repeat('a', PasswordHasherInterface::MAX_LENGTH + 1);

        $form = new SignupForm();
        $form->name = 'John Doe';
        $form->email = 'john@example.com';
        $form->password = $tooLong;
        $form->password_confirmation = $tooLong;

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('password'));
    }

    #[Test]
    public function acceptsAPasswordAtTheHasherCap(): void
    {
        $atCap = str_repeat('a', PasswordHasherInterface::MAX_LENGTH);

        $form = new SignupForm();
        $form->name = 'John Doe';
        $form->email = 'john@example.com';
        $form->password = $atCap;
        $form->password_confirmation = $atCap;

        self::assertTrue($form->validate());
    }

    #[Test]
    public function hasAllRequiredRules(): void
    {
        $form = new SignupForm();
        $rules = $form->rules();

        self::assertArrayHasKey('name', $rules);
        self::assertArrayHasKey('email', $rules);
        self::assertArrayHasKey('password', $rules);
        self::assertArrayHasKey('password_confirmation', $rules);
        self::assertContains('confirmed', $rules['password']);
        // Length rules: `min`/`max` also compare numerically, so an all-digit
        // password was weighed against the cap as a number.
        self::assertContains('min_length:' . PasswordHasherInterface::MIN_LENGTH, $rules['password']);
        self::assertContains('max_length:' . PasswordHasherInterface::MAX_LENGTH, $rules['password']);
    }
}
