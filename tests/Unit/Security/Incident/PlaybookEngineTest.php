<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Security\Incident\Playbook;
use Pulsar\Security\Incident\PlaybookEngine;
use Pulsar\Security\Incident\PlaybookOutcome;
use Pulsar\Security\Incident\PlaybookResult;
use Pulsar\Security\Incident\PlaybookStepInterface;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatResponse;
use RuntimeException;

#[CoversClass(PlaybookEngine::class)]
#[CoversClass(PlaybookResult::class)]
#[CoversClass(PlaybookOutcome::class)]
#[CoversClass(Playbook::class)]
final class PlaybookEngineTest extends TestCase
{
    private function createEvent(ThreatCategory $category = ThreatCategory::BruteForce): ThreatEvent
    {
        return new ThreatEvent(
            category: $category,
            recommendedAction: ThreatResponse::Block,
            sourceIp: '10.0.0.1',
            description: 'Test event',
            confidence: 0.95,
            detectedAt: new DateTimeImmutable(),
        );
    }

    private function createStep(string $name, bool $continue = true): PlaybookStepInterface
    {
        return new class ($name, $continue) implements PlaybookStepInterface {
            public bool $executed = false;

            public function __construct(
                private readonly string $stepName,
                private readonly bool $shouldContinue,
            ) {}

            public function execute(ThreatEvent $event): bool
            {
                $this->executed = true;
                return $this->shouldContinue;
            }

            public function name(): string
            {
                return $this->stepName;
            }
        };
    }

    private function createFailingStep(string $name): PlaybookStepInterface
    {
        return new class ($name) implements PlaybookStepInterface {
            public function execute(ThreatEvent $event): bool
            {
                throw new RuntimeException('Step failed: ' . $this->name());
            }

            public function name(): string
            {
                return $this->stepName;
            }

            public function __construct(private readonly string $stepName) {}
        };
    }

    #[Test]
    public function executesAllStepsInOrder(): void
    {
        $step1 = $this->createStep('block_ip');
        $step2 = $this->createStep('lock_account');
        $step3 = $this->createStep('notify_admin');

        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [$step1, $step2, $step3],
        );

        $engine = new PlaybookEngine([$playbook], new NullLogger());
        $result = $engine->handle($this->createEvent());

        self::assertSame(PlaybookOutcome::Completed, $result->outcome);
        self::assertSame(['block_ip', 'lock_account', 'notify_admin'], $result->executedSteps);
        self::assertTrue($result->succeeded());
        self::assertCount(3, $result->executedSteps, 'All three steps should have executed');
    }

    #[Test]
    public function haltsWhenStepReturnsFalse(): void
    {
        $step1 = $this->createStep('block_ip');
        $step2 = $this->createStep('lock_account', continue: false);
        $step3 = $this->createStep('notify_admin');

        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [$step1, $step2, $step3],
        );

        $engine = new PlaybookEngine([$playbook], new NullLogger());
        $result = $engine->handle($this->createEvent());

        self::assertSame(PlaybookOutcome::Halted, $result->outcome);
        self::assertSame(['block_ip', 'lock_account'], $result->executedSteps);
        self::assertTrue($result->succeeded());
        self::assertNotContains('notify_admin', $result->executedSteps, 'Step after halt should not execute');
    }

    #[Test]
    public function returnsNoPlaybookForUnregisteredCategory(): void
    {
        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [$this->createStep('step1')],
        );

        $engine = new PlaybookEngine([$playbook], new NullLogger());
        $result = $engine->handle($this->createEvent(ThreatCategory::ApiAbuse));

        self::assertSame(PlaybookOutcome::NoPlaybook, $result->outcome);
        self::assertSame([], $result->executedSteps);
        self::assertFalse($result->succeeded());
    }

    #[Test]
    public function returnsErrorWhenStepThrows(): void
    {
        $step1 = $this->createStep('block_ip');
        $step2 = $this->createFailingStep('lock_account');
        $step3 = $this->createStep('notify_admin');

        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [$step1, $step2, $step3],
        );

        $engine = new PlaybookEngine([$playbook], new NullLogger());
        $result = $engine->handle($this->createEvent());

        self::assertSame(PlaybookOutcome::Error, $result->outcome);
        self::assertSame('lock_account', $result->failedStep);
        self::assertStringContainsString('Step failed', $result->errorMessage);
        self::assertFalse($result->succeeded());
    }

    #[Test]
    public function hasPlaybookReturnsTrueForRegisteredCategory(): void
    {
        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [$this->createStep('step1')],
        );

        $engine = new PlaybookEngine([$playbook], new NullLogger());

        self::assertTrue($engine->hasPlaybook($this->createEvent(ThreatCategory::BruteForce)));
        self::assertFalse($engine->hasPlaybook($this->createEvent(ThreatCategory::ApiAbuse)));
    }

    #[Test]
    public function playbookEffectiveNameFallsBackToCategory(): void
    {
        $unnamed = new Playbook(trigger: ThreatCategory::BruteForce, steps: []);
        self::assertSame('playbook:brute_force', $unnamed->effectiveName());

        $named = new Playbook(trigger: ThreatCategory::BruteForce, steps: [], name: 'brute-force-response');
        self::assertSame('brute-force-response', $named->effectiveName());
    }
}
