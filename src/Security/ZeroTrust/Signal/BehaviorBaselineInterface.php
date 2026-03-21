<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use Pulsar\Api\Api;

/**
 * Port for retrieving behavioral baselines for a subject.
 *
 * Implementations are responsible for building and persisting baseline profiles
 * from historical activity data. Returns null when no baseline has been established.
 * @api
 */
#[Api(since: '1.0.0')]
interface BehaviorBaselineInterface
{
    public function getBaseline(string $subjectId): ?BehaviorBaseline;
}
