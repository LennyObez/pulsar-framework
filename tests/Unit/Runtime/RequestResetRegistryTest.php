<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\RequestResetRegistry;

#[CoversClass(RequestResetRegistry::class)]
final class RequestResetRegistryTest extends TestCase
{
    #[Test]
    public function it_starts_empty(): void
    {
        $registry = new RequestResetRegistry();

        self::assertSame([], $registry->getResettableIds());
        self::assertSame([], $registry->getEvictableIds());
    }

    #[Test]
    public function it_registers_resettable_ids(): void
    {
        $registry = new RequestResetRegistry();
        $registry->registerResettable('App\\Service\\SessionStore');
        $registry->registerResettable('App\\Service\\TenantContext');

        self::assertSame([
            'App\\Service\\SessionStore',
            'App\\Service\\TenantContext',
        ], $registry->getResettableIds());
    }

    #[Test]
    public function it_registers_evictable_ids(): void
    {
        $registry = new RequestResetRegistry();
        $registry->registerEvictable('App\\Auth\\SecurityContext');
        $registry->registerEvictable('App\\Auth\\TokenBag');

        self::assertSame([
            'App\\Auth\\SecurityContext',
            'App\\Auth\\TokenBag',
        ], $registry->getEvictableIds());
    }

    #[Test]
    public function it_deduplicates_resettable_ids(): void
    {
        $registry = new RequestResetRegistry();
        $registry->registerResettable('App\\Service\\TenantContext');
        $registry->registerResettable('App\\Service\\TenantContext');
        $registry->registerResettable('App\\Service\\TenantContext');

        self::assertCount(1, $registry->getResettableIds());
        self::assertSame(['App\\Service\\TenantContext'], $registry->getResettableIds());
    }

    #[Test]
    public function it_deduplicates_evictable_ids(): void
    {
        $registry = new RequestResetRegistry();
        $registry->registerEvictable('App\\Auth\\SecurityContext');
        $registry->registerEvictable('App\\Auth\\SecurityContext');

        self::assertCount(1, $registry->getEvictableIds());
        self::assertSame(['App\\Auth\\SecurityContext'], $registry->getEvictableIds());
    }

    #[Test]
    public function it_preserves_registration_order(): void
    {
        $registry = new RequestResetRegistry();
        $registry->registerResettable('third');
        $registry->registerResettable('first');
        $registry->registerResettable('second');

        // Should maintain insertion order, not sorted
        self::assertSame(['third', 'first', 'second'], $registry->getResettableIds());
    }

    #[Test]
    public function resettable_and_evictable_are_independent(): void
    {
        $registry = new RequestResetRegistry();
        $registry->registerResettable('shared.id');
        $registry->registerEvictable('shared.id');

        self::assertSame(['shared.id'], $registry->getResettableIds());
        self::assertSame(['shared.id'], $registry->getEvictableIds());
    }
}
