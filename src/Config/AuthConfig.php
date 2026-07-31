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
final readonly class AuthConfig implements ReportsUnknownKeys
{
    /** Keys read from the `auth` sub-array of config/security.php. */
    private const array KNOWN_KEYS = ['default_guard', 'guards', 'two_factor', 'authorization'];

    /**
     * @param list<AuthGuardConfig> $guards
     * @param list<string> $unknownKeys Keys present in the raw `auth` array that this
     *     DTO does not read, including those of the nested guards, `two_factor` and
     *     `authorization` sections.
     */
    public function __construct(
        public string $defaultGuard = 'session',
        public array $guards = [],
        public TwoFactorConfig $twoFactor = new TwoFactorConfig(),
        public AuthorizationConfig $authorization = new AuthorizationConfig(),
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
     * A copy with a different two-factor config. Used by compliance enforcement to
     * enable MFA when the active regulatory profile requires it.
     */
    #[NoDiscard]
    public function withTwoFactor(TwoFactorConfig $twoFactor): self
    {
        return clone($this, ['twoFactor' => $twoFactor]);
    }

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

        $twoFactor = TwoFactorConfig::fromArray($data['two_factor'] ?? []);
        $authorization = AuthorizationConfig::fromArray($data['authorization'] ?? []);

        // Label each guard's report by its configured name rather than its position,
        // so the warning names the guard the operator has to go and fix.
        $guardsByName = [];

        foreach ($guards as $index => $guard) {
            $guardsByName[$guard->name !== '' ? $guard->name : (string) $index] = $guard;
        }

        return new self(
            defaultGuard: $data['default_guard'] ?? 'session',
            guards: $guards,
            twoFactor: $twoFactor,
            authorization: $authorization,
            unknownKeys: [
                ...UnknownKeys::collect($data, self::KNOWN_KEYS),
                ...UnknownKeys::nestedEach('guards', $guardsByName),
                ...UnknownKeys::nested('two_factor', $twoFactor),
                ...UnknownKeys::nested('authorization', $authorization),
            ],
        );
    }
}
