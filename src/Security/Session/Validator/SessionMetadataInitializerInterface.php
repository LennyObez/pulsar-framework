<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Validator;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Security\Session\SessionMetadata;

/**
 * A session validator that seeds its own state onto freshly-created metadata.
 *
 * Validators implementing {@see SessionValidatorInterface} compare a stored
 * value against the current request. For that comparison to ever fire, the
 * value must be established when the session is first created — otherwise the
 * validator has nothing to validate against and silently passes. A validator
 * implementing this interface is given the chance to stamp its initial state
 * (e.g. a request fingerprint) onto the new session's metadata.
 * @api
 */
#[Api(since: '1.0.0')]
interface SessionMetadataInitializerInterface
{
    /**
     * Return metadata augmented with this validator's initial state.
     *
     * Called once, when a session is first created, before it is persisted.
     */
    public function initializeMetadata(SessionMetadata $metadata, ServerRequestInterface $request): SessionMetadata;
}
