<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use Pulsar\Api\Internal;

/**
 * Immutable value object describing a time-trap render stamp.
 *
 * Carries the server-side render time (`issuedAt`, a Unix timestamp) and the
 * `formId` the stamp is bound to. Both are signed into the token by
 * {@see TimeTrapService} so a client can neither backdate the stamp (to defeat
 * the minimum-fill-time check) nor replay it against a different form.
 */
#[Internal(reason: 'Time-trap payload; produced/consumed via TimeTrapService')]
final readonly class TimeTrapToken
{
    public function __construct(
        public int $issuedAt,
        public string $formId,
    ) {}
}
