<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function array_key_exists;
use function bin2hex;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * In-memory SessionInterface implementation for tests and
 * short-lived workers that don't need PHP's `$_SESSION` globals.
 *
 * The production `Session` class wraps `session_start()` +
 * `$_SESSION` directly, so tests touching it need
 * `@runInSeparateProcess` to isolate session state between
 * cases. That per-test process fork rules out parallel test
 * runners and is prohibitively slow wherever process creation is
 * expensive; it also prevents long-running SAPIs (RoadRunner,
 * FrankenPHP, Swoole) from cleanly sharing session state across
 * workers.
 *
 * `InMemorySession` stores everything in process memory keyed
 * by the configured cookie name → an ordinary array. Test
 * suites construct one per test, set / get without process
 * isolation, and discard it on tearDown. The interface contract
 * is identical to production, so any service that depends on
 * `SessionInterface` can be unit-tested with InMemorySession
 * without touching the PHP session globals.
 *
 * Production code MUST NOT use this implementation — session
 * state is lost on every request. The wiring layer picks the
 * right impl based on context (Session for HTTP, InMemorySession
 * for tests).
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];
    private string $id = '';
    private bool $started = false;

    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * @throws RandomException
     */
    #[Override]
    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->id = bin2hex($this->randomizer->getBytes(16));
        $this->started = true;
    }

    #[Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    #[Override]
    #[NoDiscard]
    public function getString(string $key, string $default = ''): string
    {
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    #[Override]
    #[NoDiscard]
    public function getNullableString(string $key): ?string
    {
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    #[Override]
    #[NoDiscard]
    public function getInt(string $key, int $default = 0): int
    {
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    #[Override]
    #[NoDiscard]
    public function getBool(string $key, bool $default = false): bool
    {
        /** @var mixed $value */
        $value = $this->data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    #[Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    #[Override]
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    /**
     * @throws RandomException
     */
    #[Override]
    public function regenerate(bool $deleteOldSession = true): void
    {
        if (!$this->started) {
            $this->start();
            return;
        }
        $previousData = $deleteOldSession ? [] : $this->data;
        $this->id = bin2hex($this->randomizer->getBytes(16));
        $this->data = $previousData;
    }

    #[Override]
    public function destroy(): void
    {
        $this->data = [];
        $this->id = '';
        $this->started = false;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function all(): array
    {
        return $this->data;
    }
}
