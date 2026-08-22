<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Validator;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Security\Session\SessionMetadata;

/**
 * Contract for session validators that verify request context matches session state.
 *
 * Validators check attributes like user agent, IP address, or fingerprint
 * to detect session hijacking or context changes.
 * @api
 */
#[Api(since: '1.0.0')]
interface SessionValidatorInterface
{
    /**
     * Validate that the current request context matches the stored session metadata.
     *
     * @return bool True if validation passes, false if the session should be invalidated
     */
    public function validate(SessionMetadata $metadata, ServerRequestInterface $request): bool;

    /**
     * Get the validator name for identification in logs and error messages.
     */
    public function getName(): string;
}
