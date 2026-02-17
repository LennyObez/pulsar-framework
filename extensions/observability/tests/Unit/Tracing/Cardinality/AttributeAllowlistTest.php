<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Cardinality;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Observability\Tracing\Cardinality\AttributeAllowlist;
use Pulsar\Extension\Observability\Tracing\Cardinality\OverflowTracker;

#[CoversClass(AttributeAllowlist::class)]
#[CoversClass(OverflowTracker::class)]
final class AttributeAllowlistTest extends TestCase
{
    #[Test]
    public function filtersToAllowedKeysOnly(): void
    {
        $allowlist = new AttributeAllowlist([
            'http' => ['method', 'status_code'],
        ]);

        $result = $allowlist->filter('http', [
            'method' => 'GET',
            'status_code' => '200',
            'user_agent' => 'Mozilla/5.0',
        ]);

        self::assertSame(['method' => 'GET', 'status_code' => '200'], $result);
    }

    #[Test]
    public function unknownScopePassesAllAttributes(): void
    {
        $allowlist = new AttributeAllowlist([
            'http' => ['method'],
        ]);

        $attributes = ['any_key' => 'value', 'other' => 'data'];
        $result = $allowlist->filter('database', $attributes);

        self::assertSame($attributes, $result);
    }

    #[Test]
    public function logsFirstOccurrenceOfUnknownKey(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('Unknown attribute key "bad_key" in scope "http"'));

        $allowlist = new AttributeAllowlist(
            allowedKeys: ['http' => ['method']],
            logger: $logger,
        );

        // First call: logs unknown key
        $allowlist->filter('http', ['method' => 'GET', 'bad_key' => 'value']);

        // Second call: same unknown key, should NOT log again
        $allowlist->filter('http', ['method' => 'POST', 'bad_key' => 'other']);
    }

    #[Test]
    public function emptyAllowlistForScopeFiltersEverything(): void
    {
        $allowlist = new AttributeAllowlist([
            'http' => [],
        ]);

        $result = $allowlist->filter('http', ['method' => 'GET']);

        self::assertSame([], $result);
    }

    #[Test]
    public function emptyAttributesReturnsEmpty(): void
    {
        $allowlist = new AttributeAllowlist([
            'http' => ['method'],
        ]);

        $result = $allowlist->filter('http', []);

        self::assertSame([], $result);
    }

    #[Test]
    public function overflowTrackerStopsLoggingAtCapacity(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // maxTrackedUnknowns = 2, so only first 2 unknown keys get logged
        $logger->expects(self::exactly(2))->method('debug');

        $allowlist = new AttributeAllowlist(
            allowedKeys: ['http' => ['method']],
            maxTrackedUnknowns: 2,
            logger: $logger,
        );

        $allowlist->filter('http', ['unknown1' => 'a']);
        $allowlist->filter('http', ['unknown2' => 'b']);
        // Third unknown key exceeds tracker capacity: no log
        $allowlist->filter('http', ['unknown3' => 'c']);
    }

    #[Test]
    public function multipleScopes(): void
    {
        $allowlist = new AttributeAllowlist([
            'http' => ['method', 'path'],
            'db' => ['statement', 'system'],
        ]);

        $httpResult = $allowlist->filter('http', [
            'method' => 'GET',
            'path' => '/api',
            'extra' => 'ignored',
        ]);

        $dbResult = $allowlist->filter('db', [
            'statement' => 'SELECT 1',
            'system' => 'mysql',
            'host' => 'localhost',
        ]);

        self::assertSame(['method' => 'GET', 'path' => '/api'], $httpResult);
        self::assertSame(['statement' => 'SELECT 1', 'system' => 'mysql'], $dbResult);
    }
}
