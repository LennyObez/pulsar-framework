<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;

/**
 * Contract for ABAC (Attribute-Based Access Control) policies.
 *
 * A policy can explicitly allow, explicitly deny, or abstain (return null).
 * Explicit deny always takes precedence over allow.
 */
#[Api]
interface PolicyInterface
{
    /**
     * Evaluate access for the given identity and context.
     *
     * @return bool|null true = allow, false = deny, null = abstain
     */
    public function evaluate(IdentityInterface $identity, PolicyContext $context): ?bool;
}
