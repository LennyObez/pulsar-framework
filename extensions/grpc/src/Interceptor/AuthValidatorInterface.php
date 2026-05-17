<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Pulsar\Api\Api;

/**
 * Contract for validating authentication tokens in gRPC calls.
 *
 * Implementations verify bearer tokens (typically JWT) and return the
 * authenticated identity string on success, or null on failure.
 * @api
 */
#[Api(since: '1.0.0')]
interface AuthValidatorInterface
{
    /**
     * Validate the given token and return the identity string.
     *
     * @return string|null Authenticated identity, or null if invalid
     */
    public function validateToken(string $token): ?string;
}
