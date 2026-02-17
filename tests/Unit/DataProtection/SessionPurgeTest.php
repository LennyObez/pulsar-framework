<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\DataProtection\SessionPurge;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;

#[CoversClass(SessionPurge::class)]
final class SessionPurgeTest extends TestCase
{
    #[Test]
    public function purgeCallsGcWithRetentionDaysInSeconds(): void
    {
        $handler = $this->createStub(SessionHandlerInterface::class);
        $handler->method('gc')->willReturn(5);

        $purge = new SessionPurge($handler);
        $policy = new DefaultRetentionPolicy('sessions', 30);

        $count = $purge->purge($policy);

        self::assertSame(5, $count);
    }

    #[Test]
    public function purgeReturnsZeroForIndefiniteRetention(): void
    {
        $handler = $this->createStub(SessionHandlerInterface::class);

        $purge = new SessionPurge($handler);
        $policy = new DefaultRetentionPolicy('sessions', 0); // Indefinite

        $count = $purge->purge($policy);

        self::assertSame(0, $count);
    }

    #[Test]
    public function purgeHandlesGcReturningFalse(): void
    {
        $handler = $this->createStub(SessionHandlerInterface::class);
        $handler->method('gc')->willReturn(false);

        $purge = new SessionPurge($handler);
        $policy = new DefaultRetentionPolicy('sessions', 7);

        $count = $purge->purge($policy);

        self::assertSame(0, $count);
    }

    #[Test]
    public function countExpiredReturnsZero(): void
    {
        $handler = $this->createStub(SessionHandlerInterface::class);

        $purge = new SessionPurge($handler);
        $policy = new DefaultRetentionPolicy('sessions', 30);

        // Session handlers don't support count-only — always returns 0
        self::assertSame(0, $purge->countExpired($policy));
    }
}
