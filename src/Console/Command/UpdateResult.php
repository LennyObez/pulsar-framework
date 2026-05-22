<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Pulsar\Api\Internal;

/**
 * Result of a self-update operation.
 */
#[Internal]
final readonly class UpdateResult
{
    public function __construct(
        public bool $success,
        public string $errorMessage = '',
        public string $notes = '',
    ) {}

    /**
     * Create a successful result.
     */
    public static function ok(string $notes = ''): self
    {
        return new self(success: true, notes: $notes);
    }

    /**
     * Create a failed result.
     */
    public static function failed(string $message): self
    {
        return new self(success: false, errorMessage: $message);
    }
}
