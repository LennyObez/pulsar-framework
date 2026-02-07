<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Stub;

use Override;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;

/**
 * In-memory session for testing form CSRF and wizard state.
 */
final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    private string $id = 'test-session-id';
    private bool $started = true;

    #[Override]
    public function start(): void
    {
        $this->started = true;
    }

    #[Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
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

    #[Override]
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->id = 'regenerated-session-id';
    }

    #[Override]
    public function destroy(): void
    {
        $this->data = [];
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
