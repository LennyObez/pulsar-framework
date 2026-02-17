<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\HijackAction;
use Pulsar\Security\Session\HijackPolicy;
use Pulsar\Security\Session\HijackVerdict;

#[CoversClass(HijackVerdict::class)]
#[CoversClass(HijackAction::class)]
#[CoversClass(HijackPolicy::class)]
final class HijackVerdictTest extends TestCase
{
    // ── HijackVerdict factories ───────────────────────────────────

    #[Test]
    public function okReturnsAllowWithEmptyReason(): void
    {
        $verdict = HijackVerdict::ok();

        self::assertSame(HijackAction::Allow, $verdict->action);
        self::assertSame('', $verdict->reason);
        self::assertTrue($verdict->isOk());
        self::assertFalse($verdict->requiresInvalidation());
    }

    #[Test]
    public function invalidateReturnsInvalidateAction(): void
    {
        $verdict = HijackVerdict::invalidate('IP changed from 1.2.3.4 to 5.6.7.8');

        self::assertSame(HijackAction::Invalidate, $verdict->action);
        self::assertSame('IP changed from 1.2.3.4 to 5.6.7.8', $verdict->reason);
        self::assertFalse($verdict->isOk());
        self::assertTrue($verdict->requiresInvalidation());
    }

    #[Test]
    public function challengeReturnsChallengeAction(): void
    {
        $verdict = HijackVerdict::challenge('User-Agent mismatch');

        self::assertSame(HijackAction::Challenge, $verdict->action);
        self::assertSame('User-Agent mismatch', $verdict->reason);
        self::assertFalse($verdict->isOk());
        self::assertFalse($verdict->requiresInvalidation());
    }

    #[Test]
    public function warnReturnsWarnAction(): void
    {
        $verdict = HijackVerdict::warn('Minor fingerprint drift');

        self::assertSame(HijackAction::Warn, $verdict->action);
        self::assertSame('Minor fingerprint drift', $verdict->reason);
        self::assertFalse($verdict->isOk());
        self::assertFalse($verdict->requiresInvalidation());
    }

    // ── HijackAction enum ─────────────────────────────────────────

    #[Test]
    #[DataProvider('hijackActionProvider')]
    public function hijackActionBackedValues(HijackAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{HijackAction, string}>
     */
    public static function hijackActionProvider(): iterable
    {
        yield 'Allow' => [HijackAction::Allow, 'allow'];
        yield 'Invalidate' => [HijackAction::Invalidate, 'invalidate'];
        yield 'Challenge' => [HijackAction::Challenge, 'challenge'];
        yield 'Warn' => [HijackAction::Warn, 'warn'];
    }

    #[Test]
    public function hijackActionHasFourCases(): void
    {
        self::assertCount(4, HijackAction::cases());
    }

    // ── HijackPolicy enum ─────────────────────────────────────────

    #[Test]
    #[DataProvider('hijackPolicyProvider')]
    public function hijackPolicyBackedValues(HijackPolicy $policy, string $expected): void
    {
        self::assertSame($expected, $policy->value);
    }

    /**
     * @return iterable<string, array{HijackPolicy, string}>
     */
    public static function hijackPolicyProvider(): iterable
    {
        yield 'Invalidate' => [HijackPolicy::Invalidate, 'invalidate'];
        yield 'Challenge' => [HijackPolicy::Challenge, 'challenge'];
        yield 'Warn' => [HijackPolicy::Warn, 'warn'];
    }

    #[Test]
    public function hijackPolicyHasThreeCases(): void
    {
        self::assertCount(3, HijackPolicy::cases());
    }
}
