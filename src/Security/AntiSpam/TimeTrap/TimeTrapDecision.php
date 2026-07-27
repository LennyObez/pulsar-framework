<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\TimeTrap;

use Pulsar\Api\Api;

/**
 * The action a caller should take after {@see TimeTrapGuard::evaluate()}.
 *
 * Lets a form without an anti-spam pipeline apply a timing defence directly and
 * act on the result, without interpreting scores.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum TimeTrapDecision
{
    /**
     * Proceed normally: the submission is not implausibly fast, carries no
     * timing signal, or the failure policy is score-only (advisory, fail-open).
     */
    case Accept;

    /**
     * A too-fast submit under the silent-accept policy: return the normal success
     * response to the client but DROP the payload (do not persist / deliver it).
     */
    case SilentlyDrop;

    /** A too-fast submit under the hard-reject policy: refuse it (e.g. HTTP 422). */
    case Reject;
}
