<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Wizard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Wizard\WizardState;

final class WizardStateTest extends TestCase
{
    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $state = new WizardState(
            wizardId: 'reg-wizard',
            currentStep: 1,
            stepCounter: 2,
            createdAt: 1700000000,
            stepData: [0 => ['name' => 'John']],
        );

        $array = $state->toArray();

        self::assertSame('reg-wizard', $array['wizard_id']);
        self::assertSame(1, $array['current_step']);
        self::assertSame(2, $array['step_counter']);
        self::assertSame(1700000000, $array['created_at']);
        self::assertSame([0 => ['name' => 'John']], $array['step_data']);
    }

    #[Test]
    public function fromArrayDeserializesCorrectly(): void
    {
        $state = WizardState::fromArray([
            'wizard_id' => 'w1',
            'current_step' => 2,
            'step_counter' => 3,
            'created_at' => 1700000000,
            'step_data' => [0 => ['a' => 'b']],
        ]);

        self::assertSame('w1', $state->wizardId);
        self::assertSame(2, $state->currentStep);
        self::assertSame(3, $state->stepCounter);
        self::assertSame(1700000000, $state->createdAt);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingData(): void
    {
        $state = WizardState::fromArray([]);

        self::assertSame('', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
        self::assertSame(0, $state->createdAt);
        self::assertSame([], $state->stepData);
    }

    #[Test]
    public function advanceMovesToNextStepAndStoresData(): void
    {
        $state = new WizardState('w1', 0, 0, 1700000000, []);

        $next = $state->advance(1, ['name' => 'John']);

        self::assertSame(1, $next->currentStep);
        self::assertSame(1, $next->stepCounter);
        self::assertSame(['name' => 'John'], $next->stepData[0]);
    }

    #[Test]
    public function goBackReturnsToPreviousStep(): void
    {
        $state = new WizardState('w1', 2, 3, 1700000000, [0 => ['a' => 'b'], 1 => ['c' => 'd']]);

        $previous = $state->goBack(1);

        self::assertSame(1, $previous->currentStep);
        self::assertSame(3, $previous->stepCounter); // counter unchanged
    }

    #[Test]
    public function advanceIsImmutable(): void
    {
        $state = new WizardState('w1', 0, 0, 1700000000, []);
        $next = $state->advance(1, ['name' => 'John']);

        self::assertSame(0, $state->currentStep);
        self::assertSame(1, $next->currentStep);
    }
}
