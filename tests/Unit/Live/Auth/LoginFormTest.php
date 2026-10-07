<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\LoginForm;

#[CoversClass(LoginForm::class)]
final class LoginFormTest extends TestCase
{
    #[Test]
    public function validatesSuccessfully(): void
    {
        $form = new LoginForm();
        $form->email = 'user@example.com';
        $form->password = 'secret123';

        self::assertTrue($form->validate());
    }

    #[Test]
    public function failsWithMissingEmail(): void
    {
        $form = new LoginForm();
        $form->email = '';
        $form->password = 'secret123';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('email'));
    }

    #[Test]
    public function failsWithInvalidEmail(): void
    {
        $form = new LoginForm();
        $form->email = 'not-email';
        $form->password = 'secret123';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('email'));
    }

    #[Test]
    public function failsWithMissingPassword(): void
    {
        $form = new LoginForm();
        $form->email = 'user@example.com';
        $form->password = '';

        self::assertFalse($form->validate());
        self::assertTrue($form->hasFieldError('password'));
    }

    #[Test]
    public function hasRulesForEmailAndPassword(): void
    {
        $form = new LoginForm();
        $rules = $form->rules();

        self::assertArrayHasKey('email', $rules);
        self::assertArrayHasKey('password', $rules);
    }
}
