<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Extension\Form\Field\PasswordField;

#[CoversClass(PasswordField::class)]
final class PasswordFieldTest extends TestCase
{
    #[Test]
    public function typeIsPassword(): void
    {
        $field = new PasswordField('pwd');
        self::assertSame('password', $field->getType());
    }

    #[Test]
    public function getValueAlwaysReturnsNull(): void
    {
        $field = new PasswordField('pwd');
        $field->setValue('secret123');
        // Access through the FieldInterface to confirm setValue does not leak
        self::assertNull($this->getFieldValue($field));
    }

    /**
     * Resolve field value via the generic interface (avoids assertNull-on-null narrowing).
     */
    private function getFieldValue(FieldInterface $field): mixed
    {
        return $field->getValue();
    }

    #[Test]
    public function minLengthIsConfigurable(): void
    {
        $field = new PasswordField('pwd', 'Password', minLength: 8);
        self::assertSame(8, $field->getMinLength());
    }

    #[Test]
    public function minLengthDefaultsToNull(): void
    {
        $field = new PasswordField('pwd');
        self::assertNull($field->getMinLength());
    }

    #[Test]
    public function placeholderIsConfigurable(): void
    {
        $field = new PasswordField('pwd', 'Password', placeholder: 'Enter password');
        self::assertSame('Enter password', $field->getPlaceholder());
    }

    #[Test]
    public function placeholderDefaultsToNull(): void
    {
        $field = new PasswordField('pwd');
        self::assertNull($field->getPlaceholder());
    }
}
