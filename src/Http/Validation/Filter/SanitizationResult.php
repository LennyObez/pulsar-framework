<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Filter;

use Pulsar\Api\Api;

/**
 * Immutable result of sanitization, preserving original values in memory.
 */
#[Api(since: '1.0.0')]
readonly class SanitizationResult
{
    /**
     * @param array<string, mixed> $sanitized The sanitized field values
     * @param array<string, mixed> $originals The original field values before sanitization
     */
    public function __construct(
        private array $sanitized,
        private array $originals,
    ) {}

    /**
     * Get all sanitized values.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->sanitized;
    }

    /**
     * Get the original value for a field before sanitization.
     */
    public function original(string $field): mixed
    {
        return $this->originals[$field] ?? null;
    }

    /**
     * Whether any field value was modified during sanitization.
     */
    public function wasModified(): bool
    {
        return $this->sanitized !== $this->originals;
    }

    /**
     * Whether a specific field was modified during sanitization.
     */
    public function fieldWasModified(string $field): bool
    {
        if (!isset($this->originals[$field])) {
            return false;
        }

        return ($this->sanitized[$field] ?? null) !== $this->originals[$field];
    }
}
