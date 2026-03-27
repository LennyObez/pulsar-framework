<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;

/**
 * Master configuration for the unified auth extension.
 *
 * Aggregates social SSO, OAuth2 server, and WebAuthn configurations
 * into a single top-level config DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthConfig
{
    public function __construct(
        public SocialSsoConfig $social,
        public OAuth2Config $oauth2,
        public WebAuthnConfig $webauthn,
    ) {}

    /**
     * @param array{
     *     social?: array<string, mixed>,
     *     oauth2?: array<string, mixed>,
     *     webauthn?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            social: SocialSsoConfig::fromArray($data['social'] ?? []),
            oauth2: OAuth2Config::fromArray($data['oauth2'] ?? []),
            webauthn: WebAuthnConfig::fromArray($data['webauthn'] ?? []),
        );
    }
}
