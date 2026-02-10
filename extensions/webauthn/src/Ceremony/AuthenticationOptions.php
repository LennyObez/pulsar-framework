<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Ceremony;

use Pulsar\Api\Api;

/**
 * Options for a WebAuthn authentication (assertion) ceremony.
 *
 * Contains the challenge and configuration to pass to the browser's
 * navigator.credentials.get() API.
 */
#[Api(since: '1.0.0')]
final readonly class AuthenticationOptions
{
    /**
     * @param array<string, mixed> $publicKeyOptions The PublicKeyCredentialRequestOptions to send to the client
     */
    public function __construct(
        public string $challenge,
        public array $publicKeyOptions,
    ) {}

    /**
     * Serialize for JSON transport to the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->publicKeyOptions;
    }
}
