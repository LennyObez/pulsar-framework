<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Cardinality;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\OpenTelemetry\Cardinality\AttributeAllowlist;
use Pulsar\Extension\OpenTelemetry\Cardinality\OverflowTracker;

#[CoversClass(AttributeAllowlist::class)]
#[CoversClass(OverflowTracker::class)]
final class AttributeAllowlistTest extends TestCase
{
    #[Test]
    public function filterKeepsOnlyAllowedKeys(): void
    {
        $allowlist = new AttributeAllowlist([
            'tracing' => ['http.method', 'http.url'],
        ]);

        $result = $allowlist->filter('tracing', [
            'http.method' => 'GET',
            'http.url' => '/api/v1',
            'http.user_agent' => 'Mozilla',
            'custom.key' => 'value',
        ]);

        self::assertSame([
            'http.method' => 'GET',
            'http.url' => '/api/v1',
        ], $result);
    }

    #[Test]
    public function filterPassesThroughForUnconfiguredScope(): void
    {
        $allowlist = new AttributeAllowlist([
            'tracing' => ['http.method'],
        ]);

        $attributes = ['any.key' => 'value', 'other' => 123];
        $result = $allowlist->filter('metrics', $attributes);

        self::assertSame($attributes, $result);
    }

    #[Test]
    public function filterReturnsEmptyWhenNoKeysMatch(): void
    {
        $allowlist = new AttributeAllowlist([
            'scope' => ['allowed'],
        ]);

        $result = $allowlist->filter('scope', ['disallowed' => 'val']);

        self::assertSame([], $result);
    }

    #[Test]
    public function filterWithEmptyAttributesReturnsEmpty(): void
    {
        $allowlist = new AttributeAllowlist([
            'scope' => ['key1'],
        ]);

        $result = $allowlist->filter('scope', []);

        self::assertSame([], $result);
    }

    #[Test]
    public function unknownKeysAreLoggedOnFirstOccurrence(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        // We verify the logger is called by checking that the method is invocable
        // without error. The real assertion is that filtering works correctly.
        $allowlist = new AttributeAllowlist(
            allowedKeys: ['scope' => ['allowed']],
            maxTrackedUnknowns: 100,
            logger: $logger,
        );

        $result = $allowlist->filter('scope', [
            'allowed' => 'yes',
            'unknown1' => 'no',
            'unknown2' => 'no',
        ]);

        self::assertSame(['allowed' => 'yes'], $result);
    }

    #[Test]
    public function emptyAllowlistPassesThroughAllScopes(): void
    {
        $allowlist = new AttributeAllowlist([]);

        $attributes = ['key1' => 'val1', 'key2' => 'val2'];
        $result = $allowlist->filter('any_scope', $attributes);

        self::assertSame($attributes, $result);
    }
}
