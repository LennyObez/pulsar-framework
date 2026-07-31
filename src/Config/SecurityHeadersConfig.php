<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_keys;
use function array_map;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;
use function strcasecmp;

/**
 * Typed configuration DTO for security headers.
 *
 * Maps from the `headers` key of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityHeadersConfig implements ReportsUnknownKeys
{
    /**
     * Baseline headers always present on every response.
     *
     * Includes a restrictive default Content-Security-Policy and the three
     * Cross-Origin isolation headers so that a misconfigured `CspConfig` /
     * `CrossOriginConfig` can never silently strip these protections (F1.3,
     * F30.4). When a richer `CspConfig` is enabled, `effectiveHeaders()`
     * overrides the baseline `Content-Security-Policy` value with the
     * configured one. HSTS is intentionally NOT in the baseline because
     * RFC 6797 §7.2 forbids emitting it over plaintext HTTP — the
     * `SecurityHeadersMiddleware` adds it conditionally on HTTPS requests
     * using the (always-enabled-by-default) `HstsConfig`.
     */
    private const array MINIMUM_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-XSS-Protection' => '0',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Content-Security-Policy' => "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Embedder-Policy' => 'require-corp',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    /**
     * @param array<string, string> $headers Header name => value pairs applied to
     *     every response. A literal entry is authoritative — "what you write is
     *     what's emitted" — and takes precedence over the matching structured
     *     sub-config for `Strict-Transport-Security` (over {@see HstsConfig}) and
     *     `Permissions-Policy` (over {@see PermissionsPolicyConfig}). A literal
     *     `Strict-Transport-Security` is still emitted only on secure requests
     *     (RFC 6797 §7.2). See {@see shadowedStructuredHeaders()} for the boot
     *     warning raised when a literal shadows an active structured config.
     * @param list<string> $unknownKeys Unrecognized keys of the NESTED sub-configs
     *     only (`csp.*`, `hsts.*`, ...), never of this level.
     *
     *     This section's own key space is open by design: every key that is not a
     *     known sub-config is a literal header name to emit, so `X-Robots-Tag` or
     *     any future header is legitimate here. Collecting unknown keys at this
     *     level would therefore report each custom header as a typo at every boot —
     *     a false-positive flood that trains operators to ignore the warning, which
     *     costs more than the gap it closes.
     */
    public function __construct(
        public array $headers,
        public CspConfig $csp = new CspConfig(),
        public HstsConfig $hsts = new HstsConfig(),
        public CrossOriginConfig $crossOrigin = new CrossOriginConfig(),
        public PermissionsPolicyConfig $permissionsPolicy = new PermissionsPolicyConfig(),
        public NelConfig $nel = new NelConfig(),
        public string $nelEndpointUrl = '',
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
     * A copy with a different HSTS config. Used by compliance enforcement to
     * assert HTTPS when the active regulatory profile requires encryption in
     * transit.
     */
    #[NoDiscard]
    public function withHsts(HstsConfig $hsts): self
    {
        return clone($this, ['hsts' => $hsts]);
    }

    /**
     * Return the effective headers: minimum defaults merged with user config,
     * plus CSP and Cross-Origin headers when enabled.
     *
     * HSTS is intentionally excluded here because RFC 6797 §7.2 forbids
     * emitting `Strict-Transport-Security` over plaintext HTTP. The middleware
     * handles HSTS separately, conditional on a secure request.
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
                if ($this->csp->reportOnly) {
                    // Report-only mode replaces the enforcing baseline so we never
                    // emit both an enforcing and a report-only CSP at once.
                    unset($headers['Content-Security-Policy']);
                }
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

        // A literal `Permissions-Policy` in `headers` is authoritative — "what you
        // write is what's emitted" — and is never overridden by the structured
        // PermissionsPolicyConfig (whose defaults are always non-empty).
        if ($this->literalHeader('Permissions-Policy') === null) {
            $permissionsPolicyValue = $this->permissionsPolicy->toHeaderValue();
            if ($permissionsPolicyValue !== '') {
                $headers['Permissions-Policy'] = $permissionsPolicyValue;
            }
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

        // Strict-Transport-Security is emitted conditionally — secure requests
        // only (RFC 6797 §7.2 forbids it over plaintext HTTP) — by the middleware
        // via effectiveHstsHeader(). Drop any literal here so a configured value
        // can never be sent unconditionally, including over plain HTTP.
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'Strict-Transport-Security') === 0) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * Resolve the `Strict-Transport-Security` value to emit, or null when none
     * applies.
     *
     * A literal `Strict-Transport-Security` set in `headers` takes precedence
     * over the structured {@see HstsConfig} ("what you write is what's emitted").
     * When no literal is set, the structured config is used while it is enabled.
     *
     * Callers MUST gate emission on a secure request: RFC 6797 §7.2 forbids
     * sending HSTS over plaintext HTTP.
     */
    #[NoDiscard]
    public function effectiveHstsHeader(): ?string
    {
        $literal = $this->literalHeader('Strict-Transport-Security');
        if ($literal !== null && $literal !== '') {
            return $literal;
        }

        if ($this->hsts->enabled) {
            return $this->hsts->toHeaderValue();
        }

        return null;
    }

    /**
     * Case-insensitive lookup of a literal header value set in `headers`.
     *
     * HTTP field names are case-insensitive (RFC 9110 §5.1), so an operator may
     * write `strict-transport-security` or `Strict-Transport-Security`. Returns
     * the configured value, or null when the header was not set literally.
     */
    #[NoDiscard]
    public function literalHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Describe literal headers that shadow an active structured sub-config with a
     * DIFFERENT value.
     *
     * The literal is authoritative; this lets the composition root warn the
     * operator once at boot that the structured config is being ignored, so the
     * override is never silent in either direction.
     *
     * @return list<string> Human-readable conflict descriptions (empty when none)
     */
    #[NoDiscard]
    public function shadowedStructuredHeaders(): array
    {
        $conflicts = [];

        $literalHsts = $this->literalHeader('Strict-Transport-Security');
        if ($literalHsts !== null && $literalHsts !== '' && $this->hsts->enabled) {
            $structuredHsts = $this->hsts->toHeaderValue();
            if ($literalHsts !== $structuredHsts) {
                $conflicts[] = sprintf(
                    'A literal "Strict-Transport-Security" header (%s) overrides the structured "hsts" config (%s); the literal value is emitted.',
                    $literalHsts,
                    $structuredHsts,
                );
            }
        }

        $literalPermissions = $this->literalHeader('Permissions-Policy');
        if ($literalPermissions !== null && $literalPermissions !== '') {
            $structuredPermissions = $this->permissionsPolicy->toHeaderValue();
            if ($structuredPermissions !== '' && $literalPermissions !== $structuredPermissions) {
                $conflicts[] = sprintf(
                    'A literal "Permissions-Policy" header (%s) overrides the structured "permissions_policy" config (%s); the literal value is emitted.',
                    $literalPermissions,
                    $structuredPermissions,
                );
            }
        }

        return $conflicts;
    }

    /**
     * Build from the raw security headers config array.
     *
     * Mixed shape: known sub-config keys (csp, hsts, ...) plus arbitrary
     * string => scalar header overrides.
     *
     * @param array<string, mixed> $data Raw `headers` sub-array from config/security.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /**
         * @var array{base_uri?: string, connect_src?: string, custom_directives?: array<string, string>, default_src?: string, enabled?: bool|int|string, font_src?: string, form_action?: string, frame_ancestors?: string, frame_src?: string, img_src?: string, media_src?: string, object_src?: string, report_only?: bool|int|string, report_to?: string, report_uri?: string, script_src?: string, style_src?: string, upgrade_insecure_requests?: bool|int|string} $cspData
         */
        $cspData = is_array($data['csp'] ?? null) ? $data['csp'] : [];
        /** @var array{enabled?: bool|int|string, include_sub_domains?: bool|int|string, max_age?: int, preload?: bool|int|string} $hstsData */
        $hstsData = is_array($data['hsts'] ?? null) ? $data['hsts'] : [];
        /** @var array<string, mixed> $crossOriginData */
        $crossOriginData = is_array($data['cross_origin'] ?? null) ? $data['cross_origin'] : [];
        /** @var array<string, mixed> $permissionsPolicyData */
        $permissionsPolicyData = is_array($data['permissions_policy'] ?? null) ? $data['permissions_policy'] : [];
        /** @var array{enabled?: bool|int|string, failure_fraction?: float|int, include_subdomains?: bool|int|string, max_age?: int, report_to?: string, success_fraction?: float|int} $nelData */
        $nelData = is_array($data['nel'] ?? null) ? $data['nel'] : [];
        /** @var mixed $rawNelEndpoint */
        $rawNelEndpoint = $data['nel_endpoint_url'] ?? '';
        $nelEndpointUrl = is_string($rawNelEndpoint) ? $rawNelEndpoint : '';

        // Remove sub-config keys before flattening scalar headers
        unset($data['csp'], $data['hsts'], $data['cross_origin'], $data['permissions_policy'], $data['nel'], $data['nel_endpoint_url']);

        /** @var array<string, string> $headers */
        $headers = array_map(
            static fn(mixed $value): string => is_string($value) ? $value : (is_scalar($value) ? (string) $value : ''),
            $data,
        );

        $csp = CspConfig::fromArray($cspData);
        $hsts = HstsConfig::fromArray($hstsData);
        $crossOrigin = CrossOriginConfig::fromArray($crossOriginData);
        $permissionsPolicy = PermissionsPolicyConfig::fromArray($permissionsPolicyData);
        $nel = NelConfig::fromArray($nelData);

        return new self(
            headers: $headers,
            csp: $csp,
            hsts: $hsts,
            crossOrigin: $crossOrigin,
            permissionsPolicy: $permissionsPolicy,
            nel: $nel,
            nelEndpointUrl: $nelEndpointUrl,
            unknownKeys: [
                ...UnknownKeys::nested('csp', $csp),
                ...UnknownKeys::nested('hsts', $hsts),
                ...UnknownKeys::nested('cross_origin', $crossOrigin),
                ...UnknownKeys::nested('permissions_policy', $permissionsPolicy),
                ...UnknownKeys::nested('nel', $nel),
            ],
        );
    }
}
