<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawDefaultGuard = $data['default_guard'] ?? 'session';
        $defaultGuard = is_string($rawDefaultGuard) ? $rawDefaultGuard : 'session';

        /** @var list<array<string, mixed>> $guardsData */
        $guardsData = $data['guards'] ?? [];
        $guards = array_map(
            static fn(array $guardData): AuthGuardConfig => AuthGuardConfig::fromArray($guardData),
            $guardsData,
        );

        /** @var array<string, mixed> $twoFactorData */
        $twoFactorData = $data['two_factor'] ?? [];

        /** @var array<string, mixed> $authorizationData */
        $authorizationData = $data['authorization'] ?? [];

        return new self(
            defaultGuard: $defaultGuard,
            guards: $guards,
            twoFactor: TwoFactorConfig::fromArray($twoFactorData),
            authorization: AuthorizationConfig::fromArray($authorizationData),
        );
    }
}
