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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public static function ok(string $notes = ''): self
    {
        return new self(success: true, notes: $notes);
    }

    /**
     * Create a failed result.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public static function failed(string $message): self
    {
        return new self(success: false, errorMessage: $message);
    }
}
