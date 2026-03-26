<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for authorization settings.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthorizationConfig
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
     * @param array{
     *     roles?: array<string, array<string, mixed>>,
     *     super_roles?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            roles: $data['roles'] ?? [],
            superRoles: $data['super_roles'] ?? [],
        );
    }
}
