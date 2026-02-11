<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Contracts\StudioNavEntry;

#[CoversClass(StudioNavEntry::class)]
final class StudioNavEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $entry = new StudioNavEntry(
            label: 'Dashboard',
            href: '/studio/dashboard',
            icon: 'home',
            order: 10,
            badge: '3',
        );

        self::assertSame('Dashboard', $entry->label);
        self::assertSame('/studio/dashboard', $entry->href);
        self::assertSame('home', $entry->icon);
        self::assertSame(10, $entry->order);
        self::assertSame('3', $entry->badge);
    }

    #[Test]
    public function badgeDefaultsToNull(): void
    {
        $entry = new StudioNavEntry(
            label: 'Logs',
            href: '/studio/logs',
            icon: 'list',
            order: 20,
        );

        self::assertNull($entry->badge);
    }
}
