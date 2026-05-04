<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\InMemorySession;

#[CoversClass(InMemorySession::class)]
final class InMemorySessionTest extends TestCase
{
    #[Test]
    public function startMarksSessionActiveAndAssignsId(): void
    {
        $session = new InMemorySession();
        self::assertFalse($session->isStarted());
        self::assertSame('', $session->id());

        $session->start();

        self::assertTrue($session->isStarted());
        self::assertNotSame('', $session->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session->id());
    }

    #[Test]
    public function setGetHasRemoveRoundTrip(): void
    {
        $session = new InMemorySession();
        $session->start();

        self::assertFalse($session->has('user_id'));

        $session->set('user_id', 42);
        self::assertTrue($session->has('user_id'));
        self::assertSame(42, $session->get('user_id'));

        $session->remove('user_id');
        self::assertFalse($session->has('user_id'));
        self::assertNull($session->get('user_id'));
        self::assertSame('default', $session->get('user_id', 'default'));
    }

    /**
     * F9.17: regenerate(deleteOldSession=true) — the default —
     * MUST replace the session ID AND drop existing data. This
     * is the post-login-rotation contract: anti-fixation prevents
     * an attacker from anchoring on a known pre-login id.
     */
    #[Test]
    public function regenerateWithDeleteFlushesData(): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('csrf', 'token-a');
        $oldId = $session->id();

        $session->regenerate(deleteOldSession: true);

        self::assertNotSame($oldId, $session->id());
        self::assertFalse($session->has('csrf'));
    }

    /**
     * F9.17: regenerate(deleteOldSession=false) — used for
     * privilege-escalation rotations where we keep the existing
     * session payload but anchor on a new id (CSRF, identity
     * row updates, etc.).
     */
    #[Test]
    public function regenerateWithoutDeletePreservesData(): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('csrf', 'token-a');
        $oldId = $session->id();

        $session->regenerate(deleteOldSession: false);

        self::assertNotSame($oldId, $session->id());
        self::assertSame('token-a', $session->get('csrf'));
    }

    #[Test]
    public function destroyClearsEverything(): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('k', 'v');

        $session->destroy();

        self::assertFalse($session->isStarted());
        self::assertSame('', $session->id());
        self::assertSame([], $session->all());
    }

    /**
     * F9.17: the test isolation property — two separate
     * `InMemorySession` instances MUST not share state. The
     * production `Session` class fails this property (both
     * share `$_SESSION` via the PHP globals); the in-memory
     * variant uses per-instance arrays so parallel tests
     * never collide.
     */
    #[Test]
    public function instancesAreFullyIsolated(): void
    {
        $a = new InMemorySession();
        $b = new InMemorySession();
        $a->start();
        $b->start();
        $a->set('shared', 'A');

        self::assertSame('A', $a->get('shared'));
        self::assertFalse($b->has('shared'));
        self::assertNotSame($a->id(), $b->id());
    }
}
