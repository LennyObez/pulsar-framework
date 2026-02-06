<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Consent;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable consent record value object.
 *
 * Records that a subject has granted specific scopes to a client.
 */
#[Api(since: '1.0.0')]
final readonly class ConsentRecord
{
    /**
     * @param list<string> $scopes The consented scope identifiers
     */
    public function __construct(
        public string $id,
        public string $subjectId,
        public string $clientId,
        public array $scopes,
        public DateTimeImmutable $grantedAt,
    ) {}
}
