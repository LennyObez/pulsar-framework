<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\CheckboxField;

#[CoversClass(CheckboxField::class)]
final class CheckboxFieldTest extends TestCase
{
    #[Test]
    public function typeIsCheckbox(): void
    {
        $field = new CheckboxField('agree');
        self::assertSame('checkbox', $field->getType());
    }

    #[Test]
    public function defaultCheckedValueIsOne(): void
    {
        $field = new CheckboxField('agree');
        self::assertSame('1', $field->getCheckedValue());
    }

    #[Test]
    public function customCheckedValue(): void
    {
        $field = new CheckboxField('agree', 'I agree', 'yes');
        self::assertSame('yes', $field->getCheckedValue());
    }

    #[Test]
    public function isCheckedReturnsFalseWhenNull(): void
    {
        $field = new CheckboxField('agree');
        self::assertFalse($field->isChecked());
    }

    #[Test]
    public function isCheckedReturnsTrueWhenValueMatchesCheckedValue(): void
    {
        $field = new CheckboxField('agree', '', '1');
        $field->setValue('1');
        self::assertTrue($field->isChecked());
    }

    #[Test]
    public function isCheckedReturnsFalseWhenValueDoesNotMatch(): void
    {
        $field = new CheckboxField('agree', '', '1');
        $field->setValue('0');
        self::assertFalse($field->isChecked());
    }

    /**
     * @return iterable<string, array{mixed, string, bool}>
     */
    public static function checkedValueEdgeCases(): iterable
    {
        yield 'int matching' => [1, '1', true];
        yield 'bool true' => [true, '1', true];
        yield 'bool false' => [false, '1', false];
        yield 'float matching' => [1.0, '1', true];
        yield 'custom value match' => ['yes', 'yes', true];
        yield 'custom value mismatch' => ['no', 'yes', false];
    }

    #[Test]
    #[DataProvider('checkedValueEdgeCases')]
    public function isCheckedWithVariousTypes(mixed $value, string $checkedValue, bool $expected): void
    {
        $field = new CheckboxField('toggle', '', $checkedValue);
        $field->setValue($value);
        self::assertSame($expected, $field->isChecked());
    }
}
