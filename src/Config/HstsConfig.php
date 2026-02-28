<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_numeric;

/**
 * Typed configuration DTO for HTTP Strict Transport Security headers.
 *
 * Maps from the `hsts` key within the `headers` section of `config/security.php`.
 */
#[Api(since: '1.0.0')]
readonly class HstsConfig
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
     * @param array<string, mixed> $data Raw `hsts` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawMaxAge = $data['max_age'] ?? 63072000;
        $maxAge = is_int($rawMaxAge) ? $rawMaxAge : (int) (is_numeric($rawMaxAge) ? $rawMaxAge : 63072000);

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            maxAge: $maxAge,
            includeSubDomains: (bool) ($data['include_sub_domains'] ?? true),
            preload: (bool) ($data['preload'] ?? false),
        );
    }
}
