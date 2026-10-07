<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

use function sprintf;

/**
 * Thrown when a scope assertion is requested for a fact that is not a scope fact.
 *
 * Only the operator can assert scope; everything else in the vocabulary is
 * observed. Asking {@see ComplianceScope} for a measured fact would silently
 * downgrade it to an assertion, which is the one substitution this design must
 * never make.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class UnknownScopeAssertionException extends LogicException
{
    #[NoDiscard]
    public static function forId(ObservationId $id): self
    {
        return new self(sprintf(
            '"%s" is not an operator scope assertion. Only scope facts may be asserted; '
                . 'every other fact must be observed.',
            $id->value,
        ));
    }
}
