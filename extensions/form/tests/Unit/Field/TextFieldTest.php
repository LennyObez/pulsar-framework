<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\TextField;

final class TextFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsText(): void
    {
        $field = new TextField('username', 'Username');

        self::assertSame('text', $field->getType());
    }

    #[Test]
    public function nameAndLabelReturnConstructorValues(): void
    {
        $field = new TextField('email', 'Email Address');

        self::assertSame('email', $field->getName());
        self::assertSame('Email Address', $field->getLabel());
    }

    #[Test]
    public function labelFallsBackToNameWhenEmpty(): void
    {
        $field = new TextField('username');

        self::assertSame('username', $field->getLabel());
    }

    #[Test]
    public function constraintsReturnProvidedValues(): void
    {
        $field = new TextField('name', 'Name', minLength: 2, maxLength: 50, placeholder: 'Enter name', pattern: '[A-Z]+');

        self::assertSame(2, $field->getMinLength());
        self::assertSame(50, $field->getMaxLength());
        self::assertSame('Enter name', $field->getPlaceholder());
        self::assertSame('[A-Z]+', $field->getPattern());
    }

    #[Test]
    public function constraintsDefaultToNull(): void
    {
        $field = new TextField('name');

        self::assertNull($field->getMinLength());
        self::assertNull($field->getMaxLength());
        self::assertNull($field->getPlaceholder());
        self::assertNull($field->getPattern());
    }

    #[Test]
    public function valueCanBeSetAndRetrieved(): void
    {
        $field = new TextField('name');
        self::assertNull($field->getValue());

        $field->setValue('John');
        self::assertSame('John', $field->getValue());
    }

    #[Test]
    public function requiredAndDisabledDefaultToFalse(): void
    {
        $field = new TextField('name');

        self::assertFalse($field->isRequired());
        self::assertFalse($field->isDisabled());
    }

    #[Test]
    public function setRequiredAndSetDisabledReturnSelf(): void
    {
        $field = new TextField('name');

        $result = $field->setRequired(true);
        self::assertSame($field, $result);
        self::assertTrue($field->isRequired());

        $result = $field->setDisabled(true);
        self::assertSame($field, $result);
        self::assertTrue($field->isDisabled());
    }

    #[Test]
    public function idAndErrorIdUseFieldName(): void
    {
        $field = new TextField('username');

        self::assertSame('field-username', $field->getId());
        self::assertSame('field-username-error', $field->getErrorId());
    }

    #[Test]
    public function attributesCanBeSetAndRetrieved(): void
    {
        $field = new TextField('name');
        $field->setAttribute('data-custom', 'value');

        self::assertSame(['data-custom' => 'value'], $field->getAttributes());
    }

    #[Test]
    public function setAttributesReplacesAll(): void
    {
        $field = new TextField('name');
        $field->setAttribute('old', 'value');
        $field->setAttributes(['new' => 'val']);

        self::assertSame(['new' => 'val'], $field->getAttributes());
    }
}
