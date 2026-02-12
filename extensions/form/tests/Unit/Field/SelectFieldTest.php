<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\SelectField;

final class SelectFieldTest extends TestCase
{
    #[Test]
    public function typeReturnsSelect(): void
    {
        self::assertSame('select', new SelectField('country')->getType());
    }

    #[Test]
    public function optionsCanBeSetAndRetrieved(): void
    {
        $options = ['us' => 'United States', 'fr' => 'France'];
        $field = new SelectField('country', 'Country', $options);

        self::assertSame($options, $field->getOptions());
    }

    #[Test]
    public function setOptionsReplacesExisting(): void
    {
        $field = new SelectField('country', 'Country', ['us' => 'United States']);
        $field->setOptions(['fr' => 'France']);

        self::assertSame(['fr' => 'France'], $field->getOptions());
    }

    #[Test]
    public function placeholderReturnsProvidedValue(): void
    {
        $field = new SelectField('country', placeholder: 'Select a country');

        self::assertSame('Select a country', $field->getPlaceholder());
    }
}
