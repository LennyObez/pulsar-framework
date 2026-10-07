<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\SelectField;

#[CoversClass(SelectField::class)]
final class SelectFieldTest extends TestCase
{
    #[Test]
    public function typeIsSelect(): void
    {
        $field = new SelectField('country');
        self::assertSame('select', $field->getType());
    }

    #[Test]
    public function constructorSetsOptions(): void
    {
        $options = ['us' => 'United States', 'uk' => 'United Kingdom'];
        $field = new SelectField('country', 'Country', $options);
        self::assertSame($options, $field->getOptions());
    }

    #[Test]
    public function setOptionsReplacesOptions(): void
    {
        $field = new SelectField('country', 'Country', ['us' => 'US']);
        $field->setOptions(['fr' => 'France']);
        self::assertSame(['fr' => 'France'], $field->getOptions());
    }

    #[Test]
    public function placeholderIsConfigurable(): void
    {
        $field = new SelectField('country', 'Country', placeholder: 'Choose a country');
        self::assertSame('Choose a country', $field->getPlaceholder());
    }

    #[Test]
    public function placeholderDefaultsToNull(): void
    {
        $field = new SelectField('country');
        self::assertNull($field->getPlaceholder());
    }

    #[Test]
    public function emptyOptions(): void
    {
        $field = new SelectField('empty');
        self::assertSame([], $field->getOptions());
    }

    #[Test]
    public function setValueAndGetValue(): void
    {
        $field = new SelectField('country', 'Country', ['us' => 'US', 'uk' => 'UK']);
        $field->setValue('uk');
        self::assertSame('uk', $field->getValue());
    }
}
