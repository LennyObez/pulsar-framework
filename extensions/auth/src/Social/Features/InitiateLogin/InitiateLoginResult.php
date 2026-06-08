<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Features\InitiateLogin;

use Pulsar\Extension\Auth\Social\Domain\PkceChallenge;

/**
 * Result DTO for initiating an OAuth social login flow.
 *
 * Contains the authorization URL the user should be redirected to,
 * the CSRF state token, and the optional PKCE challenge pair.
 */
final readonly class InitiateLoginResult
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $authorizationUrl,
        public string $state,
        public ?PkceChallenge $pkceChallenge = null,
    ) {}
}
