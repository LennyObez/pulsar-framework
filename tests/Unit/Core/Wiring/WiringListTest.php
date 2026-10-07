<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\CacheWiring;
use Pulsar\Core\Wiring\ServiceWiringInterface;
use Pulsar\Core\Wiring\WiringList;

use function array_map;
use function array_search;
use function array_unique;
use function count;

#[CoversClass(WiringList::class)]
final class WiringListTest extends TestCase
{
    #[Test]
    public function everyEntryIsAServiceWiring(): void
    {
        $wirings = WiringList::default();

        self::assertNotEmpty($wirings);
        foreach ($wirings as $wiring) {
            self::assertInstanceOf(ServiceWiringInterface::class, $wiring);
        }
    }

    #[Test]
    public function containsNoDuplicateWiringClasses(): void
    {
        $classes = array_map(static fn(ServiceWiringInterface $w): string => $w::class, WiringList::default());

        self::assertCount(count($classes), array_unique($classes), 'WiringList must not list a wiring twice');
    }

    #[Test]
    public function cacheWiringPrecedesAntiSpamWiring(): void
    {
        // Order is significant: AntiSpamWiring consumes TaggedCacheInterface,
        // which CacheWiring must bind first.
        $classes = array_map(static fn(ServiceWiringInterface $w): string => $w::class, WiringList::default());

        $cache = array_search(CacheWiring::class, $classes, true);
        $antiSpam = array_search(AntiSpamWiring::class, $classes, true);

        self::assertIsInt($cache);
        self::assertIsInt($antiSpam);
        self::assertLessThan($antiSpam, $cache, 'CacheWiring must run before AntiSpamWiring');
    }
}
