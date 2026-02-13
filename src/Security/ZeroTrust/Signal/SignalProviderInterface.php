<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;

/**
 * Contract for zero-trust signal providers.
 *
 * Each signal provider examines request context and produces a set of typed claims
 * with confidence scores. Implementations include device signal, location signal,
 * network signal, behavioral signal, and time-based signal providers.
 *
 * Signal providers MUST be idempotent: evaluating the same context twice must
 * produce equivalent claims. Providers MUST NOT cache state across requests.
 */
#[Api(since: '1.0.0')]
interface SignalProviderInterface
{
    /**
     * Evaluate the given context and produce claims.
     *
     * Returns an empty ClaimSet when the provider cannot produce claims
     * (e.g., missing required context data). Implementations must never
     * throw exceptions for missing optional data; return reduced confidence instead.
     */
    public function evaluate(SignalContext $context): ClaimSet;

    /**
     * Return the unique name of this signal provider.
     *
     * Used for logging, metrics, and configuration. Must be stable across versions.
     */
    public function name(): string;
}
