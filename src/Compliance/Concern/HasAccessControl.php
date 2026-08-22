<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate access control.
 *
 * Used by PCI-DSS, HIPAA, PSD2, NIS2, ISO 27001, eIDAS, and others.
 * @api
 */
#[Api(since: '1.0.0')]
interface HasAccessControl
{
    /**
     * Minimum password length required.
     *
     * The resolver picks the largest value across all enabled frameworks.
     */
    public function passwordMinLength(): int;

    /**
     * Session idle timeout in seconds.
     *
     * The resolver picks the smallest value (strictest timeout) across
     * all enabled frameworks.
     *
     * Returns null if the framework does not specify an idle timeout.
     */
    public function sessionIdleTimeout(): ?int;

    /**
     * MFA requirement scope.
     *
     * Returns one of: 'always', 'privileged', 'sensitive-data', or 'none'.
     * The resolver picks the broadest scope across all enabled frameworks
     * ('always' > 'privileged' > 'sensitive-data' > 'none').
     */
    public function mfaRequirement(): string;
}
