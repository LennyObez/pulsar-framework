<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function implode;
use function is_array;
use function is_string;

/**
 * Typed configuration DTO for Content Security Policy headers.
 *
 * Maps from the `csp` key within the `headers` section of `config/security.php`.
 */
#[Api(since: '1.0.0')]
readonly class CspConfig
{
    /**
     * @param array<string, string> $customDirectives Additional CSP directives not covered by named properties
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
    ) {}

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
     * @param array<string, mixed> $data Raw `csp` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawCustomDirectives = $data['custom_directives'] ?? [];
        /** @var array<string, string> $customDirectives */
        $customDirectives = is_array($rawCustomDirectives)
            ? array_filter($rawCustomDirectives, is_string(...))
            : [];

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            reportOnly: (bool) ($data['report_only'] ?? false),
            defaultSrc: self::extractString($data, 'default_src', "'self'"),
            scriptSrc: self::extractString($data, 'script_src', "'self'"),
            styleSrc: self::extractString($data, 'style_src', "'self'"),
            imgSrc: self::extractString($data, 'img_src', "'self'"),
            fontSrc: self::extractString($data, 'font_src', "'self'"),
            connectSrc: self::extractString($data, 'connect_src', "'self'"),
            mediaSrc: self::extractString($data, 'media_src', "'self'"),
            objectSrc: self::extractString($data, 'object_src', "'none'"),
            frameSrc: self::extractString($data, 'frame_src', "'self'"),
            frameAncestors: self::extractString($data, 'frame_ancestors', "'self'"),
            baseUri: self::extractString($data, 'base_uri', "'self'"),
            formAction: self::extractString($data, 'form_action', "'self'"),
            upgradeInsecureRequests: (bool) ($data['upgrade_insecure_requests'] ?? false),
            reportUri: self::extractString($data, 'report_uri', ''),
            reportTo: self::extractString($data, 'report_to', ''),
            customDirectives: $customDirectives,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
