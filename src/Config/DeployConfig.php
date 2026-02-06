<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_int;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/deploy.php`.
 */
#[Api]
readonly class DeployConfig
{
    /**
     * @param list<string> $trustedProxies IP addresses or CIDR ranges of trusted proxies
     */
    public function __construct(
        public array $trustedProxies = [],
        public int $maxPostSizeMb = 8,
        public int $maxUploadSizeMb = 10,
        public bool $http3Enabled = false,
        public int $http3AltSvcMaxAge = 86400,
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/deploy.php
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var list<string> $trustedProxies */
        $trustedProxies = $data['trusted_proxies'] ?? [];

        /** @var array<string, mixed> $requestLimits */
        $requestLimits = $data['request_limits'] ?? [];

        /** @var array<string, mixed> $http3 */
        $http3 = $data['http3'] ?? [];

        $rawMaxPost = $requestLimits['max_post_size_mb'] ?? 8;
        $rawMaxUpload = $requestLimits['max_upload_size_mb'] ?? 10;
        $rawAltSvcMaxAge = $http3['alt_svc_max_age'] ?? 86400;

        return new self(
            trustedProxies: $trustedProxies,
            maxPostSizeMb: is_int($rawMaxPost) ? $rawMaxPost : 8,
            maxUploadSizeMb: is_int($rawMaxUpload) ? $rawMaxUpload : 10,
            http3Enabled: (bool) ($http3['enabled'] ?? false),
            http3AltSvcMaxAge: is_int($rawAltSvcMaxAge) ? $rawAltSvcMaxAge : 86400,
        );
    }
}
