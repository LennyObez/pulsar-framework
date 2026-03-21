<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;

/**
 * Contract for the zero-trust policy engine.
 *
 * Evaluates a set of claims against configured policy rules to produce
 * an access decision. The engine considers claim confidence, allowed sources,
 * and rule priority when computing the result.
 *
 * Implementations must be stateless: the same ClaimSet + resource + action
 * must always produce the same PolicyEvaluationResult given the same rules.
 * @api
 */
#[Api(since: '1.0.0')]
interface PolicyEngineInterface
{
    /**
     * Evaluate claims against policy rules for the given resource and action.
     *
     * @param ClaimSet $claims The claims produced by signal providers
     * @param string $resource The resource being accessed (e.g., "/admin/users")
     * @param string $action The action being performed (e.g., "read", "write")
     */
    public function evaluate(ClaimSet $claims, string $resource, string $action): PolicyEvaluationResult;
}
