<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Typed configuration DTO for security headers.
 *
 * Maps from the `headers` key of `config/security.php`.
 */
#[Api(since: '1.0.0')]
readonly class SecurityHeadersConfig
{
    private const array MINIMUM_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-XSS-Protection' => '0',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    /**
     * @param array<string, string> $headers Header name => value pairs applied to every response
     */
    public function __construct(
        public array $headers,
        public CspConfig $csp = new CspConfig(),
        public HstsConfig $hsts = new HstsConfig(),
        public CrossOriginConfig $crossOrigin = new CrossOriginConfig(),
        public PermissionsPolicyConfig $permissionsPolicy = new PermissionsPolicyConfig(),
        public NelConfig $nel = new NelConfig(),
        public string $nelEndpointUrl = '',
    ) {}

    /**
     * Return the effective headers: minimum defaults merged with user config,
     * plus CSP and Cross-Origin headers when enabled.
     *
     * HSTS is intentionally excluded here because it requires request context
     * (must only be sent over HTTPS). The middleware handles HSTS separately.
     *
     * @return array<string, string>
     */
    #[NoDiscard]
    public function effectiveHeaders(): array
    {
        $headers = [...self::MINIMUM_HEADERS, ...$this->headers];

        if ($this->csp->enabled) {
            $cspValue = $this->csp->toHeaderValue();
            if ($cspValue !== '') {
                $headers[$this->csp->headerName()] = $cspValue;
            }
        }

        if ($this->crossOrigin->openerPolicy !== '') {
            $headers['Cross-Origin-Opener-Policy'] = $this->crossOrigin->openerPolicy;
        }

        if ($this->crossOrigin->embedderPolicy !== '') {
            $headers['Cross-Origin-Embedder-Policy'] = $this->crossOrigin->embedderPolicy;
        }

        if ($this->crossOrigin->resourcePolicy !== '') {
            $headers['Cross-Origin-Resource-Policy'] = $this->crossOrigin->resourcePolicy;
        }

        $permissionsPolicyValue = $this->permissionsPolicy->toHeaderValue();
        if ($permissionsPolicyValue !== '') {
            $headers['Permissions-Policy'] = $permissionsPolicyValue;
        }

        if ($this->nel->enabled) {
            $nelValue = $this->nel->toHeaderValue();
            if ($nelValue !== '') {
                $headers['NEL'] = $nelValue;
            }

            $reportToValue = $this->nel->toReportToHeaderValue($this->nelEndpointUrl);
            if ($reportToValue !== '') {
                $headers['Report-To'] = $reportToValue;
            }
        }

        return $headers;
    }

    /**
     * Build from the raw security headers config array.
     *
     * @param array<string, mixed> $data Raw `headers` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $cspData */
        $cspData = is_array($data['csp'] ?? null) ? $data['csp'] : [];

        /** @var array<string, mixed> $hstsData */
        $hstsData = is_array($data['hsts'] ?? null) ? $data['hsts'] : [];

        /** @var array<string, mixed> $crossOriginData */
        $crossOriginData = is_array($data['cross_origin'] ?? null) ? $data['cross_origin'] : [];

        /** @var array<string, mixed> $permissionsPolicyData */
        $permissionsPolicyData = is_array($data['permissions_policy'] ?? null) ? $data['permissions_policy'] : [];

        /** @var array<string, mixed> $nelData */
        $nelData = is_array($data['nel'] ?? null) ? $data['nel'] : [];

        $rawNelEndpoint = $data['nel_endpoint_url'] ?? '';
        $nelEndpointUrl = is_string($rawNelEndpoint) ? $rawNelEndpoint : '';

        // Remove sub-config keys before flattening scalar headers
        unset($data['csp'], $data['hsts'], $data['cross_origin'], $data['permissions_policy'], $data['nel'], $data['nel_endpoint_url']);

        /** @var array<string, string> $headers */
        $headers = array_map(
            static fn(mixed $value): string => is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''),
            $data,
        );

        return new self(
            headers: $headers,
            csp: CspConfig::fromArray($cspData),
            hsts: HstsConfig::fromArray($hstsData),
            crossOrigin: CrossOriginConfig::fromArray($crossOriginData),
            permissionsPolicy: PermissionsPolicyConfig::fromArray($permissionsPolicyData),
            nel: NelConfig::fromArray($nelData),
            nelEndpointUrl: $nelEndpointUrl,
        );
    }
}
