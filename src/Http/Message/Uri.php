<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use NoDiscard;
use Override;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Api;

use function ltrim;
use function parse_url;
use function preg_replace_callback;
use function rawurlencode;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * Immutable URI value object implementing PSR-7 UriInterface.
 *
 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement -- Psalm does not yet infer clone() return type
 */
#[Api(since: '1.0.0-rc.11')]
readonly class Uri implements UriInterface
{
    private const array DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
        'ftp' => 21,
        'ftps' => 990,
    ];

    private string $encodedPath;

    private string $encodedQuery;

    private string $encodedFragment;

    public function __construct(
        private string $scheme = '',
        private string $userInfo = '',
        private string $host = '',
        private ?int $port = null,
        string $path = '',
        string $query = '',
        string $fragment = '',
    ) {
        $this->encodedPath = self::encodePath($path);
        $this->encodedQuery = self::encodeQueryOrFragment($query);
        $this->encodedFragment = self::encodeQueryOrFragment($fragment);
    }

    /**
     * Parse a URI string into a Uri instance.
     */
    #[NoDiscard]
    public static function fromString(string $uri): self
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            throw new InvalidArgumentException(sprintf('Unable to parse URI: "%s"', $uri));
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        $fragment = $parts['fragment'] ?? '';

        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = $parts['user'];
            if (isset($parts['pass'])) {
                $userInfo .= ':' . $parts['pass'];
            }
        }

        return new self(
            scheme: $scheme,
            userInfo: $userInfo,
            host: $host,
            port: $port,
            path: $path,
            query: $query,
            fragment: $fragment,
        );
    }

    #[Override]
    #[NoDiscard]
    public function getScheme(): string
    {
        return $this->scheme;
    }

    #[Override]
    #[NoDiscard]
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        $port = $this->getPort();
        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    #[Override]
    #[NoDiscard]
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    #[Override]
    #[NoDiscard]
    public function getHost(): string
    {
        return $this->host;
    }

    #[Override]
    #[NoDiscard]
    public function getPort(): ?int
    {
        if ($this->port === null) {
            return null;
        }

        if ($this->scheme !== '' && isset(self::DEFAULT_PORTS[$this->scheme]) && self::DEFAULT_PORTS[$this->scheme] === $this->port) {
            return null;
        }

        return $this->port;
    }

    #[Override]
    #[NoDiscard]
    public function getPath(): string
    {
        return $this->encodedPath;
    }

    #[Override]
    #[NoDiscard]
    public function getQuery(): string
    {
        return $this->encodedQuery;
    }

    #[Override]
    #[NoDiscard]
    public function getFragment(): string
    {
        return $this->encodedFragment;
    }

    #[Override]
    #[NoDiscard]
    public function withScheme(string $scheme): UriInterface
    {
        $scheme = strtolower($scheme);

        return clone($this, ['scheme' => $scheme]);
    }

    #[Override]
    #[NoDiscard]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $userInfo = $user;
        if ($user !== '' && $password !== null && $password !== '') {
            $userInfo .= ':' . $password;
        }

        return clone($this, ['userInfo' => $userInfo]);
    }

    #[Override]
    #[NoDiscard]
    public function withHost(string $host): UriInterface
    {
        return clone($this, ['host' => strtolower($host)]);
    }

    #[Override]
    #[NoDiscard]
    public function withPort(?int $port): UriInterface
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw new InvalidArgumentException(sprintf('Invalid port: %d. Must be between 0 and 65535', $port));
        }

        return clone($this, ['port' => $port]);
    }

    #[Override]
    #[NoDiscard]
    public function withPath(string $path): UriInterface
    {
        return clone($this, [
            'encodedPath' => self::encodePath($path),
        ]);
    }

    #[Override]
    #[NoDiscard]
    public function withQuery(string $query): UriInterface
    {
        return clone($this, [
            'encodedQuery' => self::encodeQueryOrFragment($query),
        ]);
    }

    #[Override]
    #[NoDiscard]
    public function withFragment(string $fragment): UriInterface
    {
        return clone($this, [
            'encodedFragment' => self::encodeQueryOrFragment($fragment),
        ]);
    }

    #[Override]
    public function __toString(): string
    {
        $uri = '';

        $scheme = $this->getScheme();
        if ($scheme !== '') {
            $uri .= $scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->getPath();
        if ($authority !== '' && $path !== '' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        } elseif ($authority === '' && str_starts_with($path, '//')) {
            $path = '/' . ltrim($path, '/');
        }
        $uri .= $path;

        $query = $this->getQuery();
        if ($query !== '') {
            $uri .= '?' . $query;
        }

        $fragment = $this->getFragment();
        if ($fragment !== '') {
            $uri .= '#' . $fragment;
        }

        return $uri;
    }

    /**
     * Percent-encode a path component per RFC 3986 without double-encoding.
     */
    private static function encodePath(string $path): string
    {
        return (string) preg_replace_callback(
            '/[^a-zA-Z0-9_.~!$&\'()*+,;=:@\/%-]|%(?![a-fA-F0-9]{2})/',
            static fn(array $match): string => rawurlencode($match[0]),
            $path,
        );
    }

    /**
     * Percent-encode a query or fragment component per RFC 3986 without double-encoding.
     */
    private static function encodeQueryOrFragment(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^a-zA-Z0-9_.~!$&\'()*+,;=:@\/?%-]|%(?![a-fA-F0-9]{2})/',
            static fn(array $match): string => rawurlencode($match[0]),
            $value,
        );
    }
}
