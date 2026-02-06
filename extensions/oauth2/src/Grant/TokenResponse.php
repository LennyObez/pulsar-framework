<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Grant;

use Pulsar\Api\Api;

/**
 * DTO for OAuth2 token endpoint responses.
 *
 * Encapsulates the fields returned in a successful token response per RFC 6749 Section 5.1.
 */
#[Api(since: '1.0.0')]
final readonly class TokenResponse
{
    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $extraParams Additional response parameters (e.g., id_token)
     */
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
        public array $scopes = [],
        public ?string $refreshToken = null,
        public array $extraParams = [],
    ) {}

    /**
     * Serialize to the RFC 6749 Section 5.1 response body.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
        ];

        if ($this->refreshToken !== null) {
            $data['refresh_token'] = $this->refreshToken;
        }

        if ($this->scopes !== []) {
            $data['scope'] = implode(' ', $this->scopes);
        }

        return [...$data, ...$this->extraParams];
    }
}
