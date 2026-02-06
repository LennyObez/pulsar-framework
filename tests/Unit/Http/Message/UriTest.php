<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Uri;

#[CoversClass(Uri::class)]
final class UriTest extends TestCase
{
    #[Test]
    public function fromStringParsesFullUri(): void
    {
        $uri = Uri::fromString('https://user:pass@example.com:8443/path?query=value#fragment');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/path', $uri->getPath());
        self::assertSame('query=value', $uri->getQuery());
        self::assertSame('fragment', $uri->getFragment());
    }

    #[Test]
    public function fromStringParsesSimpleUri(): void
    {
        $uri = Uri::fromString('http://example.com/path');

        self::assertSame('http', $uri->getScheme());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame('/path', $uri->getPath());
        self::assertSame('', $uri->getQuery());
        self::assertSame('', $uri->getFragment());
    }

    #[Test]
    public function fromStringNormalizesSchemeToLowercase(): void
    {
        $uri = Uri::fromString('HTTPS://Example.COM/');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('example.com', $uri->getHost());
    }

    #[Test]
    public function defaultPortIsOmittedForHttp(): void
    {
        $uri = Uri::fromString('http://example.com:80/');

        self::assertNull($uri->getPort());
        self::assertSame('example.com', $uri->getAuthority());
    }

    #[Test]
    public function defaultPortIsOmittedForHttps(): void
    {
        $uri = Uri::fromString('https://example.com:443/');

        self::assertNull($uri->getPort());
    }

    #[Test]
    public function nonDefaultPortIsPreserved(): void
    {
        $uri = Uri::fromString('http://example.com:8080/');

        self::assertSame(8080, $uri->getPort());
        self::assertSame('example.com:8080', $uri->getAuthority());
    }

    #[Test]
    public function getAuthorityIncludesUserInfo(): void
    {
        $uri = Uri::fromString('https://user:pass@example.com/');

        self::assertSame('user:pass@example.com', $uri->getAuthority());
    }

    #[Test]
    public function getAuthorityReturnsEmptyWhenNoHost(): void
    {
        $uri = new Uri(path: '/path');

        self::assertSame('', $uri->getAuthority());
    }

    #[Test]
    public function withSchemeReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/');
        $new = $original->withScheme('https');

        self::assertNotSame($original, $new);
        self::assertSame('http', $original->getScheme());
        self::assertSame('https', $new->getScheme());
    }

    #[Test]
    public function withSchemeNormalizesToLowercase(): void
    {
        $uri = new Uri()->withScheme('HTTPS');

        self::assertSame('https', $uri->getScheme());
    }

    #[Test]
    public function withUserInfoReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/');
        $new = $original->withUserInfo('user', 'pass');

        self::assertNotSame($original, $new);
        self::assertSame('', $original->getUserInfo());
        self::assertSame('user:pass', $new->getUserInfo());
    }

    #[Test]
    public function withUserInfoOmitsEmptyPassword(): void
    {
        $uri = new Uri(host: 'example.com')->withUserInfo('user');

        self::assertSame('user', $uri->getUserInfo());
    }

    #[Test]
    public function withHostReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/');
        $new = $original->withHost('other.com');

        self::assertNotSame($original, $new);
        self::assertSame('example.com', $original->getHost());
        self::assertSame('other.com', $new->getHost());
    }

    #[Test]
    public function withHostNormalizesToLowercase(): void
    {
        $uri = new Uri()->withHost('Example.COM');

        self::assertSame('example.com', $uri->getHost());
    }

    #[Test]
    public function withPortReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/');
        $new = $original->withPort(8080);

        self::assertNotSame($original, $new);
        self::assertNull($original->getPort());
        self::assertSame(8080, $new->getPort());
    }

    #[Test]
    public function withPortAcceptsNull(): void
    {
        $uri = Uri::fromString('http://example.com:8080/');
        $new = $uri->withPort(null);

        self::assertNull($new->getPort());
    }

    #[Test]
    public function withPortThrowsForInvalidPort(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) new Uri()->withPort(70000);
    }

    #[Test]
    public function withPortThrowsForNegativePort(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) new Uri()->withPort(-1);
    }

    #[Test]
    public function withPathReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/old');
        $new = $original->withPath('/new');

        self::assertNotSame($original, $new);
        self::assertSame('/old', $original->getPath());
        self::assertSame('/new', $new->getPath());
    }

    #[Test]
    public function withQueryReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/?old=1');
        $new = $original->withQuery('new=2');

        self::assertNotSame($original, $new);
        self::assertSame('old=1', $original->getQuery());
        self::assertSame('new=2', $new->getQuery());
    }

    #[Test]
    public function withFragmentReturnsNewInstance(): void
    {
        $original = Uri::fromString('http://example.com/#old');
        $new = $original->withFragment('new');

        self::assertNotSame($original, $new);
        self::assertSame('old', $original->getFragment());
        self::assertSame('new', $new->getFragment());
    }

    #[Test]
    public function toStringReconstructsFullUri(): void
    {
        $uri = Uri::fromString('https://user:pass@example.com:8443/path?query=value#fragment');

        self::assertSame('https://user:pass@example.com:8443/path?query=value#fragment', (string) $uri);
    }

    #[Test]
    public function toStringOmitsDefaultPort(): void
    {
        $uri = Uri::fromString('https://example.com:443/path');

        self::assertSame('https://example.com/path', (string) $uri);
    }

    #[Test]
    public function toStringPrefixesPathWithSlashWhenAuthorityPresent(): void
    {
        $uri = new Uri(scheme: 'http', host: 'example.com', path: 'no-leading-slash');

        self::assertSame('http://example.com/no-leading-slash', (string) $uri);
    }

    #[Test]
    public function toStringReducesMultipleLeadingSlashesWithoutAuthority(): void
    {
        $uri = new Uri(path: '//double-slash');

        self::assertSame('/double-slash', (string) $uri);
    }

    #[Test]
    public function toStringHandlesMinimalUri(): void
    {
        $uri = new Uri();

        self::assertSame('', (string) $uri);
    }

    #[Test]
    public function toStringHandlesPathOnly(): void
    {
        $uri = new Uri(path: '/path/to/resource');

        self::assertSame('/path/to/resource', (string) $uri);
    }

    #[Test]
    public function pathEncodingPreservesAlreadyEncoded(): void
    {
        $uri = new Uri(path: '/path%20with%20spaces/file');

        self::assertSame('/path%20with%20spaces/file', $uri->getPath());
    }

    #[Test]
    public function pathEncodingEncodesSpecialCharacters(): void
    {
        $uri = new Uri(path: '/path with spaces');

        self::assertSame('/path%20with%20spaces', $uri->getPath());
    }

    #[Test]
    public function queryEncodingPreservesAlreadyEncoded(): void
    {
        $uri = new Uri(query: 'key=value%20with%20spaces');

        self::assertSame('key=value%20with%20spaces', $uri->getQuery());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'simple http' => ['http://example.com/', 'http://example.com/'],
            'with path' => ['http://example.com/foo/bar', 'http://example.com/foo/bar'],
            'with query' => ['http://example.com/?q=1&r=2', 'http://example.com/?q=1&r=2'],
            'with fragment' => ['http://example.com/#section', 'http://example.com/#section'],
            'full uri' => [
                'https://user:pass@example.com:9090/path?query=1#frag',
                'https://user:pass@example.com:9090/path?query=1#frag',
            ],
        ];
    }

    #[Test]
    #[DataProvider('roundTripProvider')]
    public function parseAndReconstructRoundTrips(string $input, string $expected): void
    {
        self::assertSame($expected, (string) Uri::fromString($input));
    }

    #[Test]
    public function getPortReturnsNullWithNoSchemeAndNoPort(): void
    {
        $uri = new Uri(host: 'example.com');

        self::assertNull($uri->getPort());
    }

    #[Test]
    public function withEmptySchemeRemovesScheme(): void
    {
        $uri = Uri::fromString('https://example.com/');
        $new = $uri->withScheme('');

        self::assertSame('', $new->getScheme());
        self::assertSame('//example.com/', (string) $new);
    }

    #[Test]
    public function withEmptyUserInfoRemovesUserInfo(): void
    {
        $uri = Uri::fromString('https://user:pass@example.com/');
        $new = $uri->withUserInfo('');

        self::assertSame('', $new->getUserInfo());
    }

    #[Test]
    public function constructorDefaultsToEmptyComponents(): void
    {
        $uri = new Uri();

        self::assertSame('', $uri->getScheme());
        self::assertSame('', $uri->getUserInfo());
        self::assertSame('', $uri->getHost());
        self::assertNull($uri->getPort());
        self::assertSame('', $uri->getPath());
        self::assertSame('', $uri->getQuery());
        self::assertSame('', $uri->getFragment());
    }
}
