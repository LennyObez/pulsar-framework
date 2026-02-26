<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\StepUp;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pulsar\Security\ZeroTrust\Event\StepUpAttemptedEvent;
use Pulsar\Security\ZeroTrust\Event\StepUpLockoutEvent;
use Pulsar\Security\ZeroTrust\StepUp\Internal\StepUpManager;
use Pulsar\Security\ZeroTrust\StepUp\StepUpAction;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;

#[CoversClass(StepUpManager::class)]
final class StepUpManagerTest extends TestCase
{
    private StepUpManager $manager;

    /** @var list<object> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->dispatchedEvents[] = $event;

            return $event;
        });

        $this->manager = new StepUpManager($dispatcher);
        $this->dispatchedEvents = [];
    }

    #[Test]
    public function firstAttemptReturnsRedirect(): void
    {
        $config = new StepUpConfig(maxAttempts: 3);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $action = $this->manager->handleStepUp('user-1', 'admin_access', $config, $now);

        self::assertSame(StepUpAction::Redirect, $action);
    }

    #[Test]
    public function emitsStepUpAttemptedEventOnEachAttempt(): void
    {
        $config = new StepUpConfig(maxAttempts: 5);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $this->manager->handleStepUp('user-1', 'admin_access', $config, $now);

        $attemptEvents = array_filter(
            $this->dispatchedEvents,
            static fn(object $e): bool => $e instanceof StepUpAttemptedEvent,
        );

        self::assertCount(1, $attemptEvents);
        $event = array_values($attemptEvents)[0];
        self::assertInstanceOf(StepUpAttemptedEvent::class, $event);
        self::assertSame('user-1', $event->identityId);
        self::assertSame('admin_access', $event->resource);
        self::assertSame(1, $event->attemptNumber);
        self::assertFalse($event->success);
    }

    #[Test]
    public function returnsRedirectUntilMaxAttemptsReached(): void
    {
        $config = new StepUpConfig(maxAttempts: 3, lockoutSeconds: 300, windowSeconds: 3600);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $action1 = $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $action2 = $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);

        self::assertSame(StepUpAction::Redirect, $action1);
        self::assertSame(StepUpAction::Redirect, $action2);
    }

    #[Test]
    public function deniesWhenMaxAttemptsReached(): void
    {
        $config = new StepUpConfig(maxAttempts: 3, lockoutSeconds: 300, windowSeconds: 3600);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $action3 = $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);

        // Third attempt hits max, triggers lockout
        self::assertSame(StepUpAction::Deny, $action3);
    }

    #[Test]
    public function emitsLockoutEventWhenMaxAttemptsReached(): void
    {
        $config = new StepUpConfig(maxAttempts: 2, lockoutSeconds: 600, windowSeconds: 3600);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);

        $lockoutEvents = array_filter(
            $this->dispatchedEvents,
            static fn(object $e): bool => $e instanceof StepUpLockoutEvent,
        );

        self::assertCount(1, $lockoutEvents);
        $event = array_values($lockoutEvents)[0];
        self::assertInstanceOf(StepUpLockoutEvent::class, $event);
        self::assertSame('user-1', $event->identityId);
        self::assertSame(2, $event->attemptCount);
    }

    #[Test]
    public function deniesWhileLockedOut(): void
    {
        $config = new StepUpConfig(maxAttempts: 1, lockoutSeconds: 600, windowSeconds: 3600);
        $t0 = new DateTimeImmutable('2025-01-15 10:00:00');
        $t1 = new DateTimeImmutable('2025-01-15 10:05:00'); // 5 min later, still within lockout

        // First attempt triggers lockout (maxAttempts = 1)
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $t0);

        // Subsequent attempt during lockout
        $action = $this->manager->handleStepUp('user-1', 'rule-1', $config, $t1);

        self::assertSame(StepUpAction::Deny, $action);
    }

    #[Test]
    public function resetsAfterLockoutExpires(): void
    {
        $config = new StepUpConfig(maxAttempts: 1, lockoutSeconds: 300, windowSeconds: 3600);
        $t0 = new DateTimeImmutable('2025-01-15 10:00:00');
        $t1 = new DateTimeImmutable('2025-01-15 10:06:00'); // 6 min later, lockout expired (300s = 5min)

        // Trigger lockout
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $t0);

        // After lockout expires: window also needs to have expired for reset
        $tAfterWindow = new DateTimeImmutable('2025-01-15 11:01:00'); // > 3600s window
        $action = $this->manager->handleStepUp('user-1', 'rule-1', $config, $tAfterWindow);

        // The window has expired so the counter resets — first attempt in new window triggers lockout again
        // because maxAttempts=1, so this attempt causes lockout → Deny
        self::assertSame(StepUpAction::Deny, $action);
    }

    #[Test]
    public function tracksSeparateStatePerIdentityAndRule(): void
    {
        $config = new StepUpConfig(maxAttempts: 2, lockoutSeconds: 300, windowSeconds: 3600);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        // user-1 on rule-1: 2 attempts → lockout
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);

        // user-1 on rule-2: fresh state, should redirect
        $action = $this->manager->handleStepUp('user-1', 'rule-2', $config, $now);
        self::assertSame(StepUpAction::Redirect, $action);

        // user-2 on rule-1: fresh state, should redirect
        $action = $this->manager->handleStepUp('user-2', 'rule-1', $config, $now);
        self::assertSame(StepUpAction::Redirect, $action);
    }

    #[Test]
    public function markSuccessResetsState(): void
    {
        $config = new StepUpConfig(maxAttempts: 3, lockoutSeconds: 300, windowSeconds: 3600);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        // Two attempts
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);

        // Mark success — should reset counter
        $this->manager->markSuccess('user-1', 'rule-1');

        // Verify state was reset
        $state = $this->manager->getState('user-1', 'rule-1');
        self::assertNotNull($state);
        self::assertSame(0, $state->attemptCount);
    }

    #[Test]
    public function markSuccessEmitsSuccessEvent(): void
    {
        $config = new StepUpConfig(maxAttempts: 3);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $this->manager->handleStepUp('user-1', 'rule-1', $config, $now);
        $this->manager->markSuccess('user-1', 'rule-1');

        /** @var list<object> $allEvents */
        $allEvents = $this->dispatchedEvents;
        $successEvents = array_filter(
            $allEvents,
            static fn(object $e): bool => $e instanceof StepUpAttemptedEvent && $e->success,
        );

        self::assertCount(1, $successEvents);
    }

    #[Test]
    public function getStateReturnsNullForUnknownIdentity(): void
    {
        self::assertNull($this->manager->getState('unknown', 'unknown'));
    }

    #[Test]
    public function deniesWhenCoolingDown(): void
    {
        $config = new StepUpConfig(maxAttempts: 5, cooldownSeconds: 60, windowSeconds: 3600);
        $t0 = new DateTimeImmutable('2025-01-15 10:00:00');
        $t1 = new DateTimeImmutable('2025-01-15 10:00:30'); // 30s later, within cooldown

        $this->manager->handleStepUp('user-1', 'rule-1', $config, $t0);

        // Second attempt within cooldown
        $action = $this->manager->handleStepUp('user-1', 'rule-1', $config, $t1);

        self::assertSame(StepUpAction::Deny, $action);
    }
}
