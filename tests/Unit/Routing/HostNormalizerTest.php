<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\HostNormalizer;

#[CoversClass(HostNormalizer::class)]
final class HostNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function authorities(): iterable
    {
        yield 'hostname with port' => ['api.example.com:8000', 'api.example.com'];
        yield 'hostname without port' => ['api.example.com', 'api.example.com'];
        yield 'subdomain with port' => ['acme.app.com:8080', 'acme.app.com'];
        yield 'ipv4 with port' => ['127.0.0.1:8000', '127.0.0.1'];
        yield 'ipv4 without port' => ['127.0.0.1', '127.0.0.1'];
        yield 'bracketed ipv6 with port' => ['[::1]:8000', '[::1]'];
        yield 'bracketed ipv6 without port' => ['[::1]', '[::1]'];
        yield 'bracketed full ipv6 with port' => ['[2001:db8::1]:443', '[2001:db8::1]'];
        yield 'bare ipv6 left intact' => ['::1', '::1'];
        yield 'empty string' => ['', ''];
    }

    #[Test]
    #[DataProvider('authorities')]
    public function stripPortRemovesOnlyThePort(string $authority, string $expected): void
    {
        self::assertSame($expected, HostNormalizer::stripPort($authority));
    }
}
