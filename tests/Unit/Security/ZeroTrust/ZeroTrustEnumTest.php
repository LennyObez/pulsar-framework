<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\StepUp\StepUpAction;

#[CoversClass(ClaimSource::class)]
#[CoversClass(PolicyDecision::class)]
#[CoversClass(StepUpAction::class)]
final class ZeroTrustEnumTest extends TestCase
{
    // ── ClaimSource ─────────────────────────────────────────────────────

    #[Test]
    public function claimSourceHasFiveCases(): void
    {
        self::assertCount(5, ClaimSource::cases());
    }

    #[Test]
    #[DataProvider('claimSourceProvider')]
    public function claimSourceBackedValues(ClaimSource $source, string $expected): void
    {
        self::assertSame($expected, $source->value);
    }

    /**
     * @return iterable<string, array{ClaimSource, string}>
     */
    public static function claimSourceProvider(): iterable
    {
        yield 'DeviceSignal' => [ClaimSource::DeviceSignal, 'device_signal'];
        yield 'LocationSignal' => [ClaimSource::LocationSignal, 'location_signal'];
        yield 'TimeSignal' => [ClaimSource::TimeSignal, 'time_signal'];
        yield 'BehaviorSignal' => [ClaimSource::BehaviorSignal, 'behavior_signal'];
        yield 'NetworkSignal' => [ClaimSource::NetworkSignal, 'network_signal'];
    }

    #[Test]
    public function claimSourceFromBackedValue(): void
    {
        self::assertSame(ClaimSource::DeviceSignal, ClaimSource::from('device_signal'));
        self::assertSame(ClaimSource::NetworkSignal, ClaimSource::from('network_signal'));
    }

    // ── PolicyDecision ──────────────────────────────────────────────────

    #[Test]
    public function policyDecisionHasThreeCases(): void
    {
        self::assertCount(3, PolicyDecision::cases());
    }

    #[Test]
    #[DataProvider('policyDecisionProvider')]
    public function policyDecisionBackedValues(PolicyDecision $decision, string $expected): void
    {
        self::assertSame($expected, $decision->value);
    }

    /**
     * @return iterable<string, array{PolicyDecision, string}>
     */
    public static function policyDecisionProvider(): iterable
    {
        yield 'Grant' => [PolicyDecision::Grant, 'grant'];
        yield 'Deny' => [PolicyDecision::Deny, 'deny'];
        yield 'StepUp' => [PolicyDecision::StepUp, 'step_up'];
    }

    #[Test]
    public function policyDecisionFromBackedValue(): void
    {
        self::assertSame(PolicyDecision::Grant, PolicyDecision::from('grant'));
        self::assertSame(PolicyDecision::StepUp, PolicyDecision::from('step_up'));
    }

    // ── StepUpAction ────────────────────────────────────────────────────

    #[Test]
    public function stepUpActionHasThreeCases(): void
    {
        self::assertCount(3, StepUpAction::cases());
    }

    #[Test]
    #[DataProvider('stepUpActionProvider')]
    public function stepUpActionBackedValues(StepUpAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{StepUpAction, string}>
     */
    public static function stepUpActionProvider(): iterable
    {
        yield 'Redirect' => [StepUpAction::Redirect, 'redirect'];
        yield 'Deny' => [StepUpAction::Deny, 'deny'];
        yield 'Allow' => [StepUpAction::Allow, 'allow'];
    }

    #[Test]
    public function stepUpActionFromBackedValue(): void
    {
        self::assertSame(StepUpAction::Redirect, StepUpAction::from('redirect'));
        self::assertSame(StepUpAction::Allow, StepUpAction::from('allow'));
    }
}
