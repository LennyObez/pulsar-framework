<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Features\InitiateLogin;

/**
 * Request DTO for initiating an OAuth social login flow.
 */
final readonly class InitiateLoginRequest
{
    public function __construct(
        public string $providerName,
        public ?string $redirectUri = null,
    ) {}
}
