<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for authorization settings.
 */
#[Api(since: '1.0.0')]
readonly class AuthorizationConfig
{
    /**
     * @param array<string, array<string, mixed>> $roles Role definitions keyed by name
     * @param list<string> $superRoles Roles that bypass all permission checks
     */
    public function __construct(
        public array $roles = [],
        public array $superRoles = [],
    ) {}

    /**
     * Build from a raw authorization config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, array<string, mixed>> $roles */
        $roles = $data['roles'] ?? [];
        /** @var list<string> $superRoles */
        $superRoles = $data['super_roles'] ?? [];

        return new self(
            roles: $roles,
            superRoles: $superRoles,
        );
    }
}
