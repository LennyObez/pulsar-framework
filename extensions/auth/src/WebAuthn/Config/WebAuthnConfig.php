<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Config;

use Pulsar\Api\Api;

use function array_values;
use function is_string;

/**
 * Configuration for the WebAuthn extension.
 * @api
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
     * @param array{
     *     rp_name?: string,
     *     rpName?: string,
     *     rp_id?: string,
     *     rpId?: string,
     *     origin?: string,
     *     user_verification?: string,
     *     userVerification?: string,
     *     attestation?: string,
     *     allowed_formats?: list<string>,
     *     allowedFormats?: list<string>,
     *     challenge_ttl_seconds?: int,
     *     challengeTtlSeconds?: int,
     *     timeout?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $allowedFormats = ['none', 'packed'];
        if (isset($data['allowed_formats']) || isset($data['allowedFormats'])) {
            $rawFormats = $data['allowed_formats'] ?? $data['allowedFormats'] ?? [];
            $allowedFormats = array_values($rawFormats);
        }

        return new self(
            rpName: $data['rp_name'] ?? $data['rpName'] ?? '',
            rpId: $data['rp_id'] ?? $data['rpId'] ?? '',
            origin: $data['origin'] ?? '',
            userVerification: $data['user_verification'] ?? $data['userVerification'] ?? 'preferred',
            attestation: $data['attestation'] ?? 'none',
            allowedFormats: $allowedFormats,
            challengeTtlSeconds: $data['challenge_ttl_seconds'] ?? $data['challengeTtlSeconds'] ?? 300,
            timeout: $data['timeout'] ?? 60000,
        );
    }
}
