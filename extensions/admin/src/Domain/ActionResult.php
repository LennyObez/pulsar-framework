<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of an admin action (create, update, delete, bulk).
 */
#[Api(since: '1.0.0')]
final readonly class ActionResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $success,
        public string $message,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function success(string $message, array $metadata = []): self
    {
        /** @var array<string, mixed> $metadata */
        return new self(success: true, message: $message, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function failure(string $message, array $metadata = []): self
    {
        /** @var array<string, mixed> $metadata */
        return new self(success: false, message: $message, metadata: $metadata);
    }
}
