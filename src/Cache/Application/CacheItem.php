<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Pulsar\Api\Api;

use function time;

/**
 * PSR-6 CacheItemInterface implementation.
 */
#[Api(since: '1.0.0')]
final class CacheItem implements CacheItemInterface
{
    private mixed $value = null;
    private bool $isHit = false;
    private ?DateTimeInterface $expiration = null;

    public function __construct(
        private readonly string $key,
    ) {}

    /**
     * Create a hit item (value found in cache).
     */
    public static function hit(string $key, mixed $value, ?DateTimeInterface $expiration = null): self
    {
        $item = new self($key);
        $item->value = $value;
        $item->isHit = true;
        $item->expiration = $expiration;

        return $item;
    }

    /**
     * Create a miss item (value not found in cache).
     */
    public static function miss(string $key): self
    {
        return new self($key);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): self
    {
        $this->expiration = $expiration;

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): self
    {
        if ($time === null) {
            $this->expiration = null;
        } elseif ($time instanceof DateInterval) {
            $this->expiration = new DateTimeImmutable()->add($time);
        } else {
            $this->expiration = DateTimeImmutable::createFromTimestamp(time() + $time);
        }

        return $this;
    }

    /**
     * Get the expiration time for internal use by CachePool.
     */
    public function getExpiration(): ?DateTimeInterface
    {
        return $this->expiration;
    }
}
