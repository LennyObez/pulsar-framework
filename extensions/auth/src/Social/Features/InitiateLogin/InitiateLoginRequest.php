<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Features\InitiateLogin;

/**
 * Request DTO for initiating an OAuth social login flow.
 */
final readonly class InitiateLoginRequest
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $providerName,
        public ?string $redirectUri = null,
    ) {}
}
