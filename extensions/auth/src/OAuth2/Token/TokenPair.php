<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Token;

use Pulsar\Api\Api;

/**
 * An access token and refresh token issued together.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TokenPair
{
    public function __construct(
        public AccessToken $accessToken,
        public RefreshToken $refreshToken,
    ) {}
}
