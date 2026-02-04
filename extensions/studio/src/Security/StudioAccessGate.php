<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Security;

use function base64_decode;
use function count;
use function explode;
use function hash_equals;
use function is_string;

use Pulsar\Api\Internal;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Http\Request;

use function str_starts_with;
use function substr;

/**
 * Environment-aware access control for Studio endpoints.
 *
 * Local: no auth required, all access.
 * Staging: requires STUDIO_ENABLED + basic auth.
 * Production: requires STUDIO_ENABLED + STUDIO_PRODUCTION_CONFIRM + auth + CIDR allowlist.
 */
#[Internal]
final readonly class StudioAccessGate
{
    private AllowlistChecker $allowlistChecker;

    public function __construct(
        private StudioSecurityConfig $config,
        private EnvironmentMode $mode,
    ) {
        $this->allowlistChecker = new AllowlistChecker($config->allowedCidrs);
    }

    /**
     * Check if the given request has access to Studio.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function check(Request $request): array
    {
        // Local mode: always allowed
        if ($this->mode === EnvironmentMode::Local) {
            return ['allowed' => true, 'reason' => null];
        }

        // Production requires explicit confirmation
        if ($this->mode === EnvironmentMode::Production && !$this->config->productionConfirm) {
            return ['allowed' => false, 'reason' => 'Production mode requires STUDIO_PRODUCTION_CONFIRM=true'];
        }

        // CIDR check
        $clientIp = $request->server('REMOTE_ADDR');
        if (is_string($clientIp) && !$this->allowlistChecker->isAllowed($clientIp)) {
            return ['allowed' => false, 'reason' => 'IP not in allowlist'];
        }

        // Auth check (staging + production)
        if ($this->config->authRequired) {
            $authResult = $this->checkBasicAuth($request);
            if (!$authResult) {
                return ['allowed' => false, 'reason' => 'Authentication required'];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    private function checkBasicAuth(Request $request): bool
    {
        if ($this->config->username === null || $this->config->password === null) {
            return false;
        }

        $authHeader = $request->header('Authorization');
        if ($authHeader === null) {
            return false;
        }

        if (!str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        return hash_equals($this->config->username, $parts[0])
            && hash_equals($this->config->password, $parts[1]);
    }
}
