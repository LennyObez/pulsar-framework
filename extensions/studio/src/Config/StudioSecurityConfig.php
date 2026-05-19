<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;

/**
 * Security configuration for Studio access control.
 */
#[Internal]
final readonly class StudioSecurityConfig
{
    /**
     * @param list<string> $allowedCidrs
     */
    public function __construct(
        public bool $authRequired = false,
        public ?string $username = null,
        public ?string $password = null,
        public array $allowedCidrs = ['127.0.0.1/8', '::1/128'],
        public bool $productionConfirm = false,
    ) {}

    /**
     * @param array{
     *     auth_required?: bool|int|string,
     *     username?: string|null,
     *     password?: string|null,
     *     allowed_cidrs?: list<string>,
     *     production_confirm?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $productionConfirm = $environment->get('STUDIO_PRODUCTION_CONFIRM') !== null
            ? $environment->get('STUDIO_PRODUCTION_CONFIRM') === 'true'
            : (bool) ($data['production_confirm'] ?? false);

        return new self(
            authRequired: (bool) ($data['auth_required'] ?? false),
            username: $environment->get('STUDIO_USERNAME') ?? $data['username'] ?? null,
            password: $environment->get('STUDIO_PASSWORD') ?? $data['password'] ?? null,
            allowedCidrs: $data['allowed_cidrs'] ?? ['127.0.0.1/8', '::1/128'],
            productionConfirm: $productionConfirm,
        );
    }
}
