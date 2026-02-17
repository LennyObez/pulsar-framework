<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    }
}
