<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Context provided to signal providers for claim evaluation.
 *
 * Carries the HTTP request, session identifier, and identity information
 * needed by signal providers to produce claims about the current request.
 */
#[Api(since: '1.0.0')]
readonly class SignalContext
{
    /**
     * @param ServerRequestInterface $request Current HTTP request
     * @param string $sessionId Active session identifier (empty if no session)
     * @param string $identityId Authenticated identity identifier (empty if anonymous)
     * @param array<string, mixed> $attributes Additional context attributes for extensibility
     */
    public function __construct(
        public ServerRequestInterface $request,
        public string $sessionId = '',
        public string $identityId = '',
        public array $attributes = [],
    ) {}

    /**
     * Retrieve a typed attribute from the context.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
