<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Wizard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Extension\Form\Wizard\WizardStep;

final class WizardStepTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $fields = ['name' => new TextField('name', 'Name')];
        $step = new WizardStep(index: 0, label: 'Personal Info', fields: $fields);

        self::assertSame(0, $step->index);
        self::assertSame('Personal Info', $step->label);
        self::assertSame($fields, $step->fields);
    }
}
