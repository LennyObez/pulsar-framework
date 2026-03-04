<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Wizard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\WizardFormConfig;
use Pulsar\Extension\Form\Exception\WizardException;
use Pulsar\Extension\Form\Wizard\ResumeTokenManager;
use Pulsar\Extension\Form\Wizard\WizardState;
use Pulsar\Extension\Form\Wizard\WizardStateMachine;
use Pulsar\Extension\Form\Wizard\WizardStep;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Session\SessionInterface;

final class WizardStateMachineTest extends TestCase
{
    private WizardFormConfig $config;
    private EncryptorInterface&Stub $encryptor;
    private SessionInterface&Stub $session;

    protected function setUp(): void
    {
        $this->config = WizardFormConfig::fromArray(['ttl' => 3600]);
        $this->encryptor = $this->createStub(EncryptorInterface::class);
        $this->encryptor->method('encrypt')->willReturnArgument(0);
        $this->encryptor->method('decrypt')->willReturnArgument(0);
        $this->session = $this->createStub(SessionInterface::class);
    }

    #[Test]
    public function start_creates_initial_state(): void
    {
        $machine = $this->createMachine(3);

        $state = $machine->start('wiz-1');

        self::assertInstanceOf(WizardState::class, $state);
        self::assertSame('wiz-1', $state->wizardId);
        self::assertSame(0, $state->currentStep);
        self::assertSame(0, $state->stepCounter);
    }

    #[Test]
    public function advance_moves_to_next_step(): void
    {
        $machine = $this->createMachine(3);
        $state = $machine->start('wiz-1');

        $advanced = $machine->advance($state, ['name' => 'Jane']);

        self::assertSame(1, $advanced->currentStep);
        self::assertSame(1, $advanced->stepCounter);
    }

    #[Test]
    public function advance_past_last_step_throws(): void
    {
        $machine = $this->createMachine(2);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['step1' => 'data']);

        $this->expectException(WizardException::class);

        $machine->advance($state, ['step2' => 'data']);
    }

    #[Test]
    public function go_back_moves_to_previous_step(): void
    {
        $machine = $this->createMachine(3);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['step1' => 'data']);

        $back = $machine->goBack($state, 0);

        self::assertSame(0, $back->currentStep);
    }

    #[Test]
    public function go_back_to_current_or_later_step_throws(): void
    {
        $machine = $this->createMachine(3);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, []);

        $this->expectException(WizardException::class);

        $machine->goBack($state, 1);
    }

    #[Test]
    public function go_back_to_negative_step_throws(): void
    {
        $machine = $this->createMachine(3);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, []);

        $this->expectException(WizardException::class);

        $machine->goBack($state, -1);
    }

    #[Test]
    public function validate_step_counter_allows_valid(): void
    {
        $machine = $this->createMachine(3);
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 1,
            stepCounter: 2,
            createdAt: time(),
            stepData: [],
        );

        $machine->validateStepCounter($state, 2);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_step_counter_detects_replay(): void
    {
        $machine = $this->createMachine(3);
        $state = new WizardState(
            wizardId: 'wiz-1',
            currentStep: 1,
            stepCounter: 3,
            createdAt: time(),
            stepData: [],
        );

        $this->expectException(WizardException::class);

        $machine->validateStepCounter($state, 1);
    }

    #[Test]
    public function is_last_step_returns_true_on_final_step(): void
    {
        $machine = $this->createMachine(2);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, []);

        self::assertTrue($machine->isLastStep($state));
    }

    #[Test]
    public function is_last_step_returns_false_on_non_final_step(): void
    {
        $machine = $this->createMachine(3);
        $state = $machine->start('wiz-1');

        self::assertFalse($machine->isLastStep($state));
    }

    #[Test]
    public function get_steps_returns_all_steps(): void
    {
        $machine = $this->createMachine(3);

        self::assertCount(3, $machine->getSteps());
    }

    #[Test]
    public function get_current_step_returns_correct_definition(): void
    {
        $machine = $this->createMachine(3);
        $state = $machine->start('wiz-1');

        $step = $machine->getCurrentStep($state);

        self::assertInstanceOf(WizardStep::class, $step);
    }

    #[Test]
    public function complete_returns_all_step_data(): void
    {
        $machine = $this->createMachine(2);
        $state = $machine->start('wiz-1');
        $state = $machine->advance($state, ['name' => 'Jane']);

        $data = $machine->complete($state);

        self::assertIsArray($data);
    }

    private function createMachine(int $stepCount): WizardStateMachine
    {
        $steps = [];
        for ($i = 0; $i < $stepCount; $i++) {
            $steps[] = new WizardStep(
                index: $i,
                label: "Step $i",
                fields: [],
            );
        }

        $resumeTokenManager = new ResumeTokenManager($this->session);

        return new WizardStateMachine(
            config: $this->config,
            encryptor: $this->encryptor,
            session: $this->session,
            resumeTokenManager: $resumeTokenManager,
            steps: $steps,
        );
    }
}
