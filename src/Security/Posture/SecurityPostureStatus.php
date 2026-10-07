<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;

/**
 * Outcome of a single security-posture check.
 *
 * Ordered by severity: {@see Ok} < {@see Degraded} < {@see Fail}. A degraded
 * item works but is weaker than it should be; a failed item is a security
 * control that is absent or inert.
 * @api
 */
#[Api(since: '1.0.0')]
enum SecurityPostureStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Fail = 'fail';

    /**
     * Severity rank for ordering and aggregation (higher is worse).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Degraded => 1,
            self::Fail => 2,
        };
    }

    /**
     * Return the worse of two statuses.
     */
    public function worst(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }
}
