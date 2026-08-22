<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\PasswordField;

final class PasswordFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsPassword(): void
    {
        self::assertSame('password', new PasswordField('pw')->getType());
    }

    #[Test]
    public function getValueAlwaysReturnsNullForSecurity(): void
    {
        $field = new PasswordField('pw');
        $field->setValue('secret123');

        // getValue() return type is `null`; passwords are never readable back.
        $value = $field->getValue();
        self::assertSame(null, $value);
    }

    #[Test]
    public function constraintsReturnProvidedValues(): void
    {
        $field = new PasswordField('pw', 'Password', minLength: 8, placeholder: 'Enter password');

        self::assertSame(8, $field->getMinLength());
        self::assertSame('Enter password', $field->getPlaceholder());
    }
}
