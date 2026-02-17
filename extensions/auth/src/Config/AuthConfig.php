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
 */
#[Api(since: '1.0.0')]
final readonly class AuthConfig
{
    public function __construct(
        public SocialSsoConfig $social,
        public OAuth2Config $oauth2,
        public WebAuthnConfig $webauthn,
    ) {}

    /** @param array<string, mixed> $data */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $socialData */
        $socialData = (array) ($data['social'] ?? []);
        /** @var array<string, mixed> $oauth2Data */
        $oauth2Data = (array) ($data['oauth2'] ?? []);
        /** @var array<string, mixed> $webauthnData */
        $webauthnData = (array) ($data['webauthn'] ?? []);

        return new self(
            social: SocialSsoConfig::fromArray($socialData),
            oauth2: OAuth2Config::fromArray($oauth2Data),
            webauthn: WebAuthnConfig::fromArray($webauthnData),
        );
    }
}
