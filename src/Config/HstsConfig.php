<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for HTTP Strict Transport Security headers.
 *
 * Maps from the `hsts` key within the `headers` section of `config/security.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HstsConfig
{
    public function __construct(
        public bool $enabled = true,
        public int $maxAge = 63072000,
        public bool $includeSubDomains = true,
        public bool $preload = false,
    ) {}

    #[NoDiscard]
    public function toHeaderValue(): string
    {
        $value = 'max-age=' . $this->maxAge;

        if ($this->includeSubDomains) {
            $value .= '; includeSubDomains';
        }

        if ($this->preload) {
            $value .= '; preload';
        }

        return $value;
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     max_age?: int,
     *     include_sub_domains?: bool|int|string,
     *     preload?: bool|int|string,
     * } $data Raw `hsts` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            maxAge: $data['max_age'] ?? 63072000,
            includeSubDomains: (bool) ($data['include_sub_domains'] ?? true),
            preload: (bool) ($data['preload'] ?? false),
        );
    }
}
