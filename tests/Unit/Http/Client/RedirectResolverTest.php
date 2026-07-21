<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\RedirectResolver;

#[CoversClass(RedirectResolver::class)]
final class RedirectResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statusProvider(): iterable
    {
        yield '301' => [301, true];
        yield '302' => [302, true];
        yield '303' => [303, true];
        yield '307' => [307, true];
        yield '308' => [308, true];
        yield '200' => [200, false];
        yield '304' => [304, false];
        yield '400' => [400, false];
        yield '500' => [500, false];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function isRedirectClassifiesStatusCodes(int $status, bool $expected): void
    {
        self::assertSame($expected, RedirectResolver::isRedirect($status));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function resolveProvider(): iterable
    {
        yield 'absolute url replaces base' => [
            'https://a.example.com/start',
            'https://b.example.com/next',
            'https://b.example.com/next',
        ];

        yield 'absolute path keeps host' => [
            'https://a.example.com/a/b/c',
            '/dashboard',
            'https://a.example.com/dashboard',
        ];

        yield 'protocol-relative inherits scheme' => [
            'https://a.example.com/start',
            '//c.example.com/x',
            'https://c.example.com/x',
        ];

        yield 'relative path resolves against directory' => [
            'https://a.example.com/api/v1/users',
            'roles',
            'https://a.example.com/api/v1/roles',
        ];

        yield 'relative path at root' => [
            'https://a.example.com/users',
            'roles',
            'https://a.example.com/roles',
        ];

        yield 'absolute path preserves port' => [
            'http://a.example.com:8080/start',
            '/next',
            'http://a.example.com:8080/next',
        ];

        yield 'attacker redirect to metadata is returned verbatim (guard rejects it next hop)' => [
            'https://a.example.com/start',
            'http://169.254.169.254/latest/meta-data/',
            'http://169.254.169.254/latest/meta-data/',
        ];
    }

    #[Test]
    #[DataProvider('resolveProvider')]
    public function resolveComputesTheNextUrl(string $base, string $location, string $expected): void
    {
        self::assertSame($expected, RedirectResolver::resolve($base, $location));
    }

    #[Test]
    public function resolveTrimsWhitespace(): void
    {
        self::assertSame(
            'https://a.example.com/next',
            RedirectResolver::resolve('https://a.example.com/start', "  /next \r\n"),
        );
    }
}
