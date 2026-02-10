<?php

declare(strict_types=1);

namespace Pulsar\View\Sandbox;

use Closure;
use Pulsar\Api\Internal;

/**
 * Callback type for translating keys in the sandbox.
 *
 * Provides a safe way to hook translation without exposing the full
 * translator to untrusted template execution.
 */
#[Internal(reason: 'Sandbox callback type')]
final readonly class TranslationCallback
{
    /** @var Closure(string, array<string, mixed>): string */
    private Closure $callback;

    /**
     * @param callable(string, array<string, mixed>): string $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __invoke(string $key, array $data = []): string
    {
        return ($this->callback)($key, $data);
    }
}
