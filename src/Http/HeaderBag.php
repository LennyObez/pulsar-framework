<?php

declare(strict_types=1);

namespace Pulsar\Http;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Immutable HTTP headers collection.
 *
 * Header names are case-insensitive per RFC 7230.
 *
 * @implements IteratorAggregate<string, list<string>>
 */
readonly class HeaderBag implements IteratorAggregate
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
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(array $headers = [])
    {
        $normalized = [];
        $originalNames = [];

        foreach ($headers as $name => $value) {
            $lowered = strtolower($name);
            $normalized[$lowered] = is_array($value) ? array_values($value) : [$value];
            $originalNames[$lowered] = $name;
        }

        $this->headers = $normalized;
        $this->originalNames = $originalNames;
    }

    /**
     * Get all values for a header.
     *
     * @return list<string>
     */
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
    public function with(string $name, string|array $value): self
    {
        $headers = $this->toArray();
        $headers[$name] = is_array($value) ? $value : [$value];
        return new self($headers);
    }

    /**
     * Return a new HeaderBag with an additional header value.
     */
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
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * Create from PHP's $_SERVER superglobal.
     *
     * @param array<string, mixed> $server
     */
    public static function fromServer(array $server): self
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }

        return new self($headers);
    }
}
