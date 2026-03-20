<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Config;

use Pulsar\Api\Api;

/**
 * Configuration for the WebAuthn extension.
 */
#[Api(since: '1.0.0')]
final readonly class WebAuthnConfig
{
    /**
     * @param string $rpName Relying Party display name
     * @param string $rpId Relying Party identifier (domain, e.g. "example.com")
     * @param string $origin Expected origin (e.g. "https://example.com")
     * @param string $userVerification User verification requirement: required|preferred|discouraged
     * @param string $attestation Attestation conveyance preference: none|direct|indirect
     * @param list<string> $allowedFormats Allowed attestation statement formats
     * @param int $challengeTtlSeconds How long a challenge remains valid
     * @param int $timeout Timeout for WebAuthn ceremonies in milliseconds
     */
    public function __construct(
        public string $rpName,
        public string $rpId,
        public string $origin,
        public string $userVerification = 'preferred',
        public string $attestation = 'none',
        public array $allowedFormats = ['none', 'packed'],
        public int $challengeTtlSeconds = 300,
        public int $timeout = 60000,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rpName: (string) ($data['rp_name'] ?? $data['rpName'] ?? ''),
            rpId: (string) ($data['rp_id'] ?? $data['rpId'] ?? ''),
            origin: (string) ($data['origin'] ?? ''),
            userVerification: (string) ($data['user_verification'] ?? $data['userVerification'] ?? 'preferred'),
            attestation: (string) ($data['attestation'] ?? 'none'),
            allowedFormats: isset($data['allowed_formats']) || isset($data['allowedFormats'])
                ? array_values(array_map(strval(...), (array) ($data['allowed_formats'] ?? $data['allowedFormats'])))
                : ['none', 'packed'],
            challengeTtlSeconds: (int) ($data['challenge_ttl_seconds'] ?? $data['challengeTtlSeconds'] ?? 300),
            timeout: (int) ($data['timeout'] ?? 60000),
        );
    }
}
