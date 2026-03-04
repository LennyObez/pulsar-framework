<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ApiSigning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ApiSigning\SignedRequestComponents;

#[CoversClass(SignedRequestComponents::class)]
final class SignedRequestComponentsTest extends TestCase
{
    #[Test]
    public function canonicalStringFormatsCorrectly(): void
    {
        $components = new SignedRequestComponents(
            method: 'POST',
            path: '/api/v1/orders',
            timestamp: '2025-03-15T10:30:00Z',
            bodyHash: 'abc123def456',
            keyId: 'key-1',
        );

        $expected = "POST\n/api/v1/orders\n2025-03-15T10:30:00Z\nabc123def456";

        self::assertSame($expected, $components->canonicalString());
    }

    #[Test]
    public function canonicalStringWithGetRequest(): void
    {
        $components = new SignedRequestComponents(
            method: 'GET',
            path: '/api/v1/users?page=1',
            timestamp: '2025-01-01T00:00:00Z',
            bodyHash: 'e3b0c44298fc1c149afbf4c8',
            keyId: 'key-2',
        );

        self::assertStringStartsWith('GET', $components->canonicalString());
        self::assertStringContainsString('/api/v1/users?page=1', $components->canonicalString());
    }

    #[Test]
    public function canonicalStringExcludesKeyId(): void
    {
        $components = new SignedRequestComponents(
            method: 'DELETE',
            path: '/api/v1/items/42',
            timestamp: '2025-06-01T12:00:00Z',
            bodyHash: '',
            keyId: 'secret-key-id',
        );

        $canonical = $components->canonicalString();

        self::assertStringNotContainsString('secret-key-id', $canonical);
        self::assertSame("DELETE\n/api/v1/items/42\n2025-06-01T12:00:00Z\n", $canonical);
    }

    #[Test]
    public function canonicalStringWithEmptyBodyHash(): void
    {
        $components = new SignedRequestComponents(
            method: 'GET',
            path: '/',
            timestamp: 't',
            bodyHash: '',
            keyId: 'k',
        );

        self::assertSame("GET\n/\nt\n", $components->canonicalString());
    }
}
