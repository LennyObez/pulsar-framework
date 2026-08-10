<?php

declare(strict_types=1);

namespace Pulsar\Http;

use ArrayIterator;
use IteratorAggregate;
use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Exception\UnsafeHeaderException;
use Traversable;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Immutable HTTP headers collection.
 *
 * Header names are case-insensitive per RFC 7230.
 *
 * @implements IteratorAggregate<string, list<string>>
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HeaderBag implements IteratorAggregate
{
    /**
     * Headers stored with lowercase names.
     *
     * @var array<string, list<string>>
     */
    private array $headers;

    /**
     * Original header names for case preservation.
     *
     * @var array<string, string>
     */
    private array $originalNames;

    /**
     * RFC 7230 §3.2 token characters: ASCII letters, digits and a fixed set of
     * punctuation. Anything else (whitespace, CR, LF, NUL, non-ASCII) in a
     * header NAME would either confuse downstream proxies or open the door
     * to header smuggling, so the constructor refuses it.
     */
    private const string HEADER_NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/';

    /**
     * RFC 7230 §3.2.6 permits only VCHAR, SP and HTAB (0x09) inside a header
     * value, so any other control character (NUL, CR, LF, and the rest of
     * \x00-\x08, \x0A-\x1F, \x7F) is forbidden and enables header smuggling.
     */
    private const string VALUE_CONTROL_CHAR_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @throws UnsafeHeaderException When a name or value violates RFC 7230 §3.2.
     */
    public function __construct(array $headers = [])
    {
        $normalized = [];
        $originalNames = [];

        foreach ($headers as $name => $value) {
            self::assertValidName($name);
            $values = is_array($value) ? array_values($value) : [$value];

            foreach ($values as $candidate) {
                self::assertValidValue($name, $candidate);
            }

            $lowered = strtolower($name);
            $normalized[$lowered] = $values;
            $originalNames[$lowered] = $name;
        }

        $this->headers = $normalized;
        $this->originalNames = $originalNames;
    }

    /**
     * @throws UnsafeHeaderException
     */
    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw UnsafeHeaderException::emptyName();
        }

        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
            throw UnsafeHeaderException::invalidNameCharacters($name);
        }
    }

    /**
     * @throws UnsafeHeaderException
     */
    private static function assertValidValue(string $name, string $value): void
    {
        // Reject NUL, CR, LF and any other ASCII control character in the
        // header value (RFC 7230 §3.2.6 — see VALUE_CONTROL_CHAR_PATTERN).
        if (preg_match(self::VALUE_CONTROL_CHAR_PATTERN, $value) === 1) {
            throw UnsafeHeaderException::controlCharactersInValue($name);
        }
    }

    /**
     * Get all values for a header.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function get(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * Get the first value for a header, or default.
     */
    public function first(string $name, ?string $default = null): ?string
    {
        $values = $this->get($name);
        return $values[0] ?? $default;
    }

    /**
     * Check if a header exists.
     */
    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Return a new HeaderBag with the header set.
     *
     * @param string|list<string> $value
     */
    #[NoDiscard]
    public function with(string $name, string|array $value): self
    {
        $headers = $this->toArray();
        $headers[$name] = is_array($value) ? $value : [$value];
        return new self($headers);
    }

    /**
     * Return a new HeaderBag with an additional header value.
     */
    #[NoDiscard]
    public function withAdded(string $name, string $value): self
    {
        $headers = $this->toArray();
        $lowered = strtolower($name);

        if (isset($this->headers[$lowered])) {
            $originalName = $this->originalNames[$lowered];
            $headers[$originalName] = [...$this->headers[$lowered], $value];
        } else {
            $headers[$name] = [$value];
        }

        return new self($headers);
    }

    /**
     * Return a new HeaderBag without the specified header.
     */
    #[NoDiscard]
    public function without(string $name): self
    {
        $lowered = strtolower($name);

        if (!isset($this->headers[$lowered])) {
            return $this;
        }

        $headers = $this->toArray();
        $originalName = $this->originalNames[$lowered];
        unset($headers[$originalName]);

        return new self($headers);
    }

    /**
     * Get all headers as an array with original case.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->headers as $lowered => $values) {
            $result[$this->originalNames[$lowered]] = $values;
        }

        return $result;
    }

    /**
     * Get all headers as lines suitable for HTTP.
     *
     * @return list<string>
     */
    public function toLines(): array
    {
        $lines = [];

        foreach ($this->headers as $lowered => $values) {
            $name = $this->originalNames[$lowered];
            foreach ($values as $value) {
                $lines[] = sprintf('%s: %s', $name, $value);
            }
        }

        return $lines;
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->headers === [];
    }

    /**
     * Get the number of unique headers.
     */
    public function count(): int
    {
        return count($this->headers);
    }

    /**
     * @return Traversable<string, list<string>>
     */
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * Create from PHP's $_SERVER superglobal.
     *
     * @param array<string, mixed> $server
     */
    #[NoDiscard]
    public static function fromServer(array $server): self
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', $key);
            } else {
                continue;
            }

            // $_SERVER is untrusted external input. Silently drop header-
            // injection attempts (control characters in the value, malformed
            // names) here rather than letting the strict constructor throw:
            // request parsing must stay robust against hostile input. A request
            // left without a valid Host is rejected later as a 400.
            if (
                preg_match(self::HEADER_NAME_PATTERN, $name) !== 1
                || preg_match(self::VALUE_CONTROL_CHAR_PATTERN, $value) === 1
            ) {
                continue;
            }

            $headers[$name] = $value;
        }

        return new self($headers);
    }
}
