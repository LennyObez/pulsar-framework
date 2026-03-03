<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Config;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

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
        $str = static fn(mixed $v, string $default): string => is_string($v) ? $v : $default;
        $int = static fn(mixed $v, int $default): int => is_int($v) ? $v : $default;

        /** @var string $rpName */
        $rpName = $str($data['rp_name'] ?? $data['rpName'] ?? null, '');
        /** @var string $rpId */
        $rpId = $str($data['rp_id'] ?? $data['rpId'] ?? null, '');
        /** @var string $origin */
        $origin = $str($data['origin'] ?? null, '');
        /** @var string $uv */
        $uv = $str($data['user_verification'] ?? $data['userVerification'] ?? null, 'preferred');
        /** @var string $attestation */
        $attestation = $str($data['attestation'] ?? null, 'none');
        /** @var int $challengeTtl */
        $challengeTtl = $int($data['challenge_ttl_seconds'] ?? $data['challengeTtlSeconds'] ?? null, 300);
        /** @var int $timeout */
        $timeout = $int($data['timeout'] ?? null, 60000);

        $allowedFormats = ['none', 'packed'];
        if (isset($data['allowed_formats']) || isset($data['allowedFormats'])) {
            $rawFormats = (array) ($data['allowed_formats'] ?? $data['allowedFormats'] ?? []);
            $allowedFormats = array_values(array_map(
                static fn(mixed $v): string => is_string($v) ? $v : '',
                $rawFormats,
            ));
        }

        return new self(
            rpName: $rpName,
            rpId: $rpId,
            origin: $origin,
            userVerification: $uv,
            attestation: $attestation,
            allowedFormats: $allowedFormats,
            challengeTtlSeconds: $challengeTtl,
            timeout: $timeout,
        );
    }
}
