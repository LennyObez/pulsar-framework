<?php

declare(strict_types=1);

namespace Pulsar\Audit;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Context for auditable mutations.
 *
 * Required for all write operations across Pulsar subsystems (ORM, Admin, etc.)
 * to ensure every mutation carries an auditable actor, reason, and correlation ID.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MutationContext
{
    public function __construct(
        public string $actor,
        public string $reason,
        public ?string $correlationId = null,
    ) {}

    /**
     * Create a context for system-initiated mutations.
     */
    #[NoDiscard]
    public static function system(string $reason, ?string $correlationId = null): self
    {
        return new self(
            actor: 'system',
            reason: $reason,
            correlationId: $correlationId,
        );
    }
}
