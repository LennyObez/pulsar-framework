<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Represents a single Link header hint for preloading/preconnecting.
 */
#[Api(since: '1.0.0')]
final readonly class LinkHint
{
    public function __construct(
        public string $href,
        public string $rel,
        public ?string $as = null,
        public bool $crossOrigin = false,
    ) {}

    /**
     * Generate the Link header value for this hint.
     *
     * Examples:
     *   <https://example.com/style.css>; rel=preload; as=style
     *   <https://cdn.example.com>; rel=preconnect; crossorigin
     *   <https://example.com/font.woff2>; rel=preload; as=font; crossorigin
     */
    #[NoDiscard]
    public function toHeaderValue(): string
    {
        $parts = [sprintf('<%s>; rel=%s', $this->href, $this->rel)];

        if ($this->as !== null) {
            $parts[] = sprintf('as=%s', $this->as);
        }

        if ($this->crossOrigin) {
            $parts[] = 'crossorigin';
        }

        return implode('; ', $parts);
    }
}
