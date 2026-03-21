<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Domain;

use Pulsar\Api\Api;

/**
 * Result of a social identity linking operation.
 *
 * Captures whether the link was established, the resulting
 * identity ID, and which action was taken.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LinkedIdentityResult
{
    /**
     * @param bool $linked        Whether the identity is now linked to a local account
     * @param ?string $identityId Local identity record ID (null if rejected)
     * @param LinkAction $action  The action that was taken during linking
     */
    public function __construct(
        public bool $linked,
        public ?string $identityId,
        public LinkAction $action,
    ) {}
}
