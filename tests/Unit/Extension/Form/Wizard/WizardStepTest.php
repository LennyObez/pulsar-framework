<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Wizard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Wizard\WizardStep;

#[CoversClass(WizardStep::class)]
final class WizardStepTest extends TestCase
{
    #[Test]
    public function propertiesAreAccessible(): void
    {
        $field = new TextField('name', 'Name');
        $step = new WizardStep(
            index: 0,
            label: 'Personal Info',
            fields: ['name' => $field],
        );

        self::assertSame(0, $step->index);
        self::assertSame('Personal Info', $step->label);
        self::assertSame(['name' => $field], $step->fields);
    }

    #[Test]
    public function emptyFieldsStep(): void
    {
        $step = new WizardStep(index: 2, label: 'Confirmation', fields: []);

        self::assertSame(2, $step->index);
        self::assertSame('Confirmation', $step->label);
        self::assertSame([], $step->fields);
    }

    #[Test]
    public function multipleFieldsStep(): void
    {
        $name = new TextField('name', 'Name');
        $email = new TextField('email', 'Email');

        $step = new WizardStep(
            index: 1,
            label: 'Contact',
            fields: ['name' => $name, 'email' => $email],
        );

        self::assertCount(2, $step->fields);
        self::assertSame($name, $step->fields['name']);
        self::assertSame($email, $step->fields['email']);
    }
}
