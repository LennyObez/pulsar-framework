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
final readonly class AuthorizationConfig implements ReportsUnknownKeys
{
    /** Keys read from the `auth.authorization` sub-array of config/security.php. */
    private const array KNOWN_KEYS = ['roles', 'super_roles'];

    /**
     * @param array<string, array<string, mixed>> $roles Role definitions keyed by name
     * @param list<string> $superRoles Roles that bypass all permission checks
     * @param list<string> $unknownKeys Keys present in the raw `authorization` array
     *     that this DTO does not read — `super_role` written singular silently grants
     *     nobody the bypass the operator intended to grant.
     */
    public function __construct(
        public array $roles = [],
        public array $superRoles = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
