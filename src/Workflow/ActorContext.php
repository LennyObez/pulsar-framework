<?php

declare(strict_types=1);

namespace Pulsar\Workflow;

use InvalidArgumentException;
use Pulsar\Api\Api;

/**
 * Structured context identifying the actor performing a workflow transition.
 *
 * Guards and storage operations receive this to identify who is acting,
 * enabling audit logging and authorization checks. All fields are captured
 * at the moment of action and stored immutably in the transition log.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ActorContext
{
    public function __construct(
        public string $subjectId,
        public ?string $tenantId = null,
        public ?string $claimsSnapshotId = null,
    ) {
        if ($subjectId === '') {
            throw new InvalidArgumentException('ActorContext subjectId must not be empty');
        }
    }
}
