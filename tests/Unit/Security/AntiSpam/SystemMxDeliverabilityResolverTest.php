<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\Internal\SystemMxDeliverabilityResolver;

#[CoversClass(SystemMxDeliverabilityResolver::class)]
final class SystemMxDeliverabilityResolverTest extends TestCase
{
    #[Test]
    public function deliverableWhenAnMxRecordExists(): void
    {
        $resolver = new SystemMxDeliverabilityResolver(null, 86400, $this->lookup([
            'example.com:MX' => true,
        ]));

        self::assertTrue($resolver->isDeliverable('example.com'));
    }

    #[Test]
    public function deliverableWhenOnlyAnAddressRecordExists(): void
    {
        // RFC 5321 §5.1 implicit MX: an A/AAAA record is enough.
        $resolver = new SystemMxDeliverabilityResolver(null, 86400, $this->lookup([
            'host.example:A' => true,
        ]));

        self::assertTrue($resolver->isDeliverable('host.example'));
    }

    #[Test]
    public function undeliverableWhenNoRecordsButTheResolverIsAlive(): void
    {
        // No records for the target, but the liveness canary resolves, so the
        // negative is real.
        $resolver = new SystemMxDeliverabilityResolver(null, 86400, $this->lookup([
            'example.com:A' => true, // canary alive
        ]));

        self::assertFalse($resolver->isDeliverable('nope.example'));
    }

    #[Test]
    public function unknownAndFailsOpenWhenTheResolverItselfIsDown(): void
    {
        // Everything returns false, including the canary → resolver unreachable.
        $resolver = new SystemMxDeliverabilityResolver(null, 86400, $this->lookup([]));

        self::assertNull($resolver->isDeliverable('anything.example'));
    }

    #[Test]
    public function servesPositiveAndNegativeResultsFromCacheWithoutLookup(): void
    {
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('yes');

        $resolver = new SystemMxDeliverabilityResolver($cache, 86400, $this->failingLookup());

        self::assertTrue($resolver->isDeliverable('cached.example'));
    }

    #[Test]
    public function cachesAResolvedResultButNeverTheUnknown(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        // A definitive negative is cached; the unknown (fail-open) is not.
        $cache->expects(self::once())->method('set');

        $resolver = new SystemMxDeliverabilityResolver($cache, 3600, $this->lookup([
            'example.com:A' => true, // canary alive -> target is a real negative
        ]));

        self::assertFalse($resolver->isDeliverable('nope.example'));
    }

    /**
     * @param array<string, bool> $truthy Keys "domain:TYPE" that resolve true
     * @return Closure(string, string): bool
     */
    private function lookup(array $truthy): Closure
    {
        return static fn(string $domain, string $type): bool => $truthy[$domain . ':' . $type] ?? false;
    }

    /**
     * @return Closure(string, string): bool
     */
    private function failingLookup(): Closure
    {
        return static function (string $domain, string $type): bool {
            throw new LogicException('lookup must not run on a cache hit');
        };
    }
}
