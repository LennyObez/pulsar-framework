<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Flash;

use Pulsar\Api\Api;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;
use function is_array;

/**
 * Flash message storage backed by the session.
 *
 * Flash data persists for exactly one request: data written via `set()` becomes
 * available on the next request via `get()`, then is automatically purged.
 * Call `age()` once per request (typically in middleware) to rotate the bags.
 * @api
 */
#[Api(since: '1.0.0')]
final class FlashBag
{
    private const string KEY_NEW = '_flash.new';
    private const string KEY_OLD = '_flash.old';

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    /**
     * Rotate flash data: move "new" → "old" and clear "new".
     *
     * Must be called once per request before reading flash data.
     */
    public function age(): void
    {
        $new = $this->getNewBag();
        $this->session->set(self::KEY_OLD, $new);
        $this->session->set(self::KEY_NEW, []);
    }

    /**
     * Set a flash value for the next request.
     */
    public function set(string $key, mixed $value): void
    {
        $new = [...$this->getNewBag(), $key => $value];
        $this->session->set(self::KEY_NEW, $new);
    }

    /**
     * Get and remove a flash value from the current request.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        /** @var array<string, mixed> $old */
        $old = $this->getOldBag();

        if (!array_key_exists($key, $old)) {
            return $default;
        }

        /** @var mixed $value */
        $value = $old[$key];
        unset($old[$key]);
        $this->session->set(self::KEY_OLD, $old);

        return $value;
    }

    /**
     * Check if a flash value exists for the current request.
     */
    public function has(string $key): bool
    {
        $old = $this->getOldBag();

        return array_key_exists($key, $old);
    }

    /**
     * Peek at a flash value without removing it.
     */
    public function peek(string $key, mixed $default = null): mixed
    {
        $old = $this->getOldBag();

        return $old[$key] ?? $default;
    }

    /**
     * Get and clear all flash values for the current request.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $old = $this->getOldBag();
        $this->session->set(self::KEY_OLD, []);

        return $old;
    }

    /**
     * Reflash specified keys so they survive another request.
     */
    public function keep(string ...$keys): void
    {
        $old = $this->getOldBag();
        $kept = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $old)) {
                $kept = [...$kept, $key => $old[$key]];
            }
        }

        if ($kept === []) {
            return;
        }

        $new = [...$this->getNewBag(), ...$kept];
        $this->session->set(self::KEY_NEW, $new);
    }

    /**
     * Clear all flash data (both new and old).
     */
    public function clear(): void
    {
        $this->session->set(self::KEY_NEW, []);
        $this->session->set(self::KEY_OLD, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function getNewBag(): array
    {
        /** @var mixed $bag */
        $bag = $this->session->get(self::KEY_NEW, []);

        /** @var array<string, mixed> */
        return is_array($bag) ? $bag : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getOldBag(): array
    {
        /** @var mixed $bag */
        $bag = $this->session->get(self::KEY_OLD, []);

        /** @var array<string, mixed> */
        return is_array($bag) ? $bag : [];
    }
}
