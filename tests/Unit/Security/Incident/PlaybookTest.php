<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\Playbook;
use Pulsar\Security\Incident\PlaybookStepInterface;
use Pulsar\Security\ThreatDetection\ThreatCategory;

#[CoversClass(Playbook::class)]
final class PlaybookTest extends TestCase
{
    public function testConstruction(): void
    {
        $step1 = $this->createStub(PlaybookStepInterface::class);
        $step2 = $this->createStub(PlaybookStepInterface::class);

        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [$step1, $step2],
            name: 'brute_force_response',
        );

        self::assertSame(ThreatCategory::BruteForce, $playbook->trigger);
        self::assertCount(2, $playbook->steps);
        self::assertSame('brute_force_response', $playbook->name);
    }

    public function testEffectiveNameWithExplicitName(): void
    {
        $playbook = new Playbook(
            trigger: ThreatCategory::BruteForce,
            steps: [],
            name: 'my_playbook',
        );

        self::assertSame('my_playbook', $playbook->effectiveName());
    }

    public function testEffectiveNameFallsBackToTrigger(): void
    {
        $playbook = new Playbook(
            trigger: ThreatCategory::InjectionAttempt,
            steps: [],
        );

        self::assertSame('playbook:injection_attempt', $playbook->effectiveName());
    }

    public function testEffectiveNameWithEmptyName(): void
    {
        $playbook = new Playbook(
            trigger: ThreatCategory::ApiAbuse,
            steps: [],
            name: '',
        );

        self::assertSame('playbook:api_abuse', $playbook->effectiveName());
    }
}
