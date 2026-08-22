<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_filter;
use function array_map;
use function implode;
use function is_string;
use function str_contains;

/**
 * Typed configuration DTO for Content Security Policy headers.
 *
 * Maps from the `csp` key within the `headers` section of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CspConfig implements ReportsUnknownKeys
{
    /**
     * Keys read from the `headers.csp` sub-array of config/security.php.
     * Directives beyond these named properties belong under `custom_directives`,
     * an open map by design — which is what lets this list stay closed.
     */
    private const array KNOWN_KEYS = [
        'enabled', 'report_only', 'default_src', 'script_src', 'style_src', 'img_src',
        'font_src', 'connect_src', 'media_src', 'object_src', 'frame_src',
        'frame_ancestors', 'base_uri', 'form_action', 'upgrade_insecure_requests',
        'report_uri', 'report_to', 'custom_directives',
    ];

    /**
     * @param array<string, string> $customDirectives Additional CSP directives not covered by named properties
     * @param list<string> $unknownKeys Keys present in the raw `csp` array that this DTO
     *     does not read. A CSP directive misspelled here (`scripts_src`, or a hyphenated
     *     `script-src` instead of the snake_case key) silently leaves the directive at
     *     its restrictive default — the policy still holds, but not the one written.
     */
    public function __construct(
        public bool $enabled = true,
        public bool $reportOnly = false,
        public string $defaultSrc = "'self'",
        public string $scriptSrc = "'self'",
        public string $styleSrc = "'self'",
        public string $imgSrc = "'self'",
        public string $fontSrc = "'self'",
        public string $connectSrc = "'self'",
        public string $mediaSrc = "'self'",
        public string $objectSrc = "'none'",
        public string $frameSrc = "'self'",
        public string $frameAncestors = "'self'",
        public string $baseUri = "'self'",
        public string $formAction = "'self'",
        public bool $upgradeInsecureRequests = false,
        public string $reportUri = '',
        public string $reportTo = '',
        public array $customDirectives = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    #[NoDiscard]
    public function toHeaderValue(): string
    {
        if (!$this->enabled) {
            return '';
        }

        $directives = array_filter([
            'default-src' => $this->defaultSrc,
            'script-src' => $this->scriptSrc,
            'style-src' => $this->styleSrc,
            'img-src' => $this->imgSrc,
            'font-src' => $this->fontSrc,
            'connect-src' => $this->connectSrc,
            'media-src' => $this->mediaSrc,
            'object-src' => $this->objectSrc,
            'frame-src' => $this->frameSrc,
            'frame-ancestors' => $this->frameAncestors,
            'base-uri' => $this->baseUri,
            'form-action' => $this->formAction,
        ], static fn(string $value): bool => $value !== '');

        $parts = array_map(
            static fn(string $directive, string $value): string => $directive . ' ' . $value,
            array_keys($directives),
            array_values($directives),
        );

        if ($this->upgradeInsecureRequests) {
            $parts[] = 'upgrade-insecure-requests';
        }

        if ($this->reportUri !== '') {
            $parts[] = 'report-uri ' . $this->reportUri;
        }

        if ($this->reportTo !== '') {
            $parts[] = 'report-to ' . $this->reportTo;
        }

        foreach ($this->customDirectives as $directive => $value) {
            $parts[] = $directive . ' ' . $value;
        }

        return implode('; ', $parts);
    }

    #[NoDiscard]
    public function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     report_only?: bool|int|string,
     *     default_src?: string,
     *     script_src?: string,
     *     style_src?: string,
     *     img_src?: string,
     *     font_src?: string,
     *     connect_src?: string,
     *     media_src?: string,
     *     object_src?: string,
     *     frame_src?: string,
     *     frame_ancestors?: string,
     *     base_uri?: string,
     *     form_action?: string,
     *     upgrade_insecure_requests?: bool|int|string,
     *     report_uri?: string,
     *     report_to?: string,
     *     custom_directives?: array<string, string>,
     * } $data Raw `csp` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $customDirectives = array_filter($data['custom_directives'] ?? [], is_string(...));

        // A literal ';' is the CSP directive separator and is never valid inside a
        // single directive name or value. Permitting it would let a config override
        // inject an arbitrary additional directive (e.g. a permissive default-src),
        // silently neutralising the entire policy. Reject it at config-build time.
        $reportUri = $data['report_uri'] ?? '';

        if (str_contains($reportUri, ';')) {
            throw ConfigException::invalidValue('headers.csp.report_uri', "must not contain ';'");
        }

        foreach ($customDirectives as $directive => $value) {
            if (str_contains($directive, ';') || str_contains($value, ';')) {
                throw ConfigException::invalidValue(
                    'headers.csp.custom_directives.' . $directive,
                    "directive name and value must not contain ';'",
                );
            }
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            reportOnly: (bool) ($data['report_only'] ?? false),
            defaultSrc: $data['default_src'] ?? "'self'",
            scriptSrc: $data['script_src'] ?? "'self'",
            styleSrc: $data['style_src'] ?? "'self'",
            imgSrc: $data['img_src'] ?? "'self'",
            fontSrc: $data['font_src'] ?? "'self'",
            connectSrc: $data['connect_src'] ?? "'self'",
            mediaSrc: $data['media_src'] ?? "'self'",
            objectSrc: $data['object_src'] ?? "'none'",
            frameSrc: $data['frame_src'] ?? "'self'",
            frameAncestors: $data['frame_ancestors'] ?? "'self'",
            baseUri: $data['base_uri'] ?? "'self'",
            formAction: $data['form_action'] ?? "'self'",
            upgradeInsecureRequests: (bool) ($data['upgrade_insecure_requests'] ?? false),
            reportUri: $reportUri,
            reportTo: $data['report_to'] ?? '',
            customDirectives: $customDirectives,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
