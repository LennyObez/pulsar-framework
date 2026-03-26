<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;

/**
 * Typed configuration DTO for authentication and authorization.
 *
 * Maps from the `auth` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuthConfig
{
    /**
     * @param list<AuthGuardConfig> $guards
     */
    public function __construct(
        public string $defaultGuard = 'session',
        public array $guards = [],
        public TwoFactorConfig $twoFactor = new TwoFactorConfig(),
        public AuthorizationConfig $authorization = new AuthorizationConfig(),
    ) {}

    /**
     * Build from a raw auth config array.
     *
     * @param array{
     *     default_guard?: string,
     *     guards?: list<array<string, mixed>>,
     *     two_factor?: array<string, mixed>,
     *     authorization?: array<string, mixed>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $guards = array_map(
            static fn(array $guardData): AuthGuardConfig => AuthGuardConfig::fromArray($guardData),
            $data['guards'] ?? [],
        );

        return new self(
            defaultGuard: $data['default_guard'] ?? 'session',
            guards: $guards,
            twoFactor: TwoFactorConfig::fromArray($data['two_factor'] ?? []),
            authorization: AuthorizationConfig::fromArray($data['authorization'] ?? []),
        );
    }
}
