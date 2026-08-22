<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Security configuration for the admin panel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdminSecurityConfig
{
    public function __construct(
        public string $requiredRole,
        public bool $require2fa,
        public bool $csrfRotation,
        public bool $cspNonce,
    ) {}

    /**
     * @param array{
     *     required_role?: string,
     *     require_2fa?: bool,
     *     csrf_rotation?: bool,
     *     csp_nonce?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            requiredRole: $data['required_role'] ?? 'admin',
            require2fa: $data['require_2fa'] ?? true,
            csrfRotation: $data['csrf_rotation'] ?? true,
            cspNonce: $data['csp_nonce'] ?? true,
        );
    }
}
