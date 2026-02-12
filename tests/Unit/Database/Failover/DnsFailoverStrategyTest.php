<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Failover\DnsFailoverStrategy;

#[CoversClass(DnsFailoverStrategy::class)]
final class DnsFailoverStrategyTest extends TestCase
{
    #[Test]
    public function resolveTargetReturnsResolvedHost(): void
    {
        // localhost should resolve to 127.0.0.1 on all platforms
        $strategy = new DnsFailoverStrategy('localhost');

        $result = $strategy->resolveTarget();

        // On most systems, localhost resolves to 127.0.0.1
        // If DNS lookup fails, gethostbyname fallback is used
        self::assertNotNull($result);
        self::assertSame('127.0.0.1', $result);
    }

    #[Test]
    public function nameReturnsDns(): void
    {
        $strategy = new DnsFailoverStrategy('example.com');

        self::assertSame('dns', $strategy->name());
    }

    #[Test]
    public function unresolvedHostReturnsNull(): void
    {
        // Use a hostname that will not resolve
        $strategy = new DnsFailoverStrategy('this-host-does-not-exist-anywhere.invalid');

        $result = $strategy->resolveTarget();

        // gethostbyname returns the hostname unchanged if it cannot resolve
        // and dns_get_record returns false/empty — so result should be null
        self::assertNull($result);
    }
}
