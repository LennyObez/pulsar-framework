<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\CheckboxField;

final class CheckboxFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsCheckbox(): void
    {
        self::assertSame('checkbox', new CheckboxField('agree')->getType());
    }

    #[Test]
    public function checkedValueDefaultsToOne(): void
    {
        self::assertSame('1', new CheckboxField('agree')->getCheckedValue());
    }

    #[Test]
    public function customCheckedValue(): void
    {
        $field = new CheckboxField('agree', checkedValue: 'yes');

        self::assertSame('yes', $field->getCheckedValue());
    }

    #[Test]
    public function isCheckedReturnsTrueWhenValueMatchesCheckedValue(): void
    {
        $field = new CheckboxField('agree');
        $field->setValue('1');

        self::assertTrue($field->isChecked());
    }

    #[Test]
    public function isCheckedReturnsFalseWhenValueDoesNotMatch(): void
    {
        $field = new CheckboxField('agree');
        $field->setValue('0');

        self::assertFalse($field->isChecked());
    }

    #[Test]
    public function isCheckedReturnsFalseWhenValueIsNull(): void
    {
        $field = new CheckboxField('agree');

        self::assertFalse($field->isChecked());
    }
}
