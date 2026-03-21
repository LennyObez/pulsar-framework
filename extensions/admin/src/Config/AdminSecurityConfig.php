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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var string $requiredRole */
        $requiredRole = $data['required_role'] ?? 'admin';
        /** @var bool $require2fa */
        $require2fa = $data['require_2fa'] ?? true;
        /** @var bool $csrfRotation */
        $csrfRotation = $data['csrf_rotation'] ?? true;
        /** @var bool $cspNonce */
        $cspNonce = $data['csp_nonce'] ?? true;

        return new self(
            requiredRole: $requiredRole,
            require2fa: $require2fa,
            csrfRotation: $csrfRotation,
            cspNonce: $cspNonce,
        );
    }
}
