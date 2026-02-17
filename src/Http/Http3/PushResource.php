<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * A single resource to push/preload via Link header.
 */
#[Api(since: '1.0.0')]
final readonly class PushResource
{
    public function __construct(
        public string $path,
        public string $as,
        public bool $noPush = false,
        public bool $crossOrigin = false,
    ) {}

    /**
     * Generate the Link header value.
     *
     * Examples:
     *   </css/app.css>; rel=preload; as=style
     *   </js/app.js>; rel=preload; as=script; nopush
     *   </fonts/inter.woff2>; rel=preload; as=font; crossorigin
     */
    #[NoDiscard]
    public function toHeaderValue(): string
    {
        $parts = [sprintf('<%s>; rel=preload; as=%s', $this->path, $this->as)];

        if ($this->crossOrigin) {
            $parts[] = 'crossorigin';
        }

        if ($this->noPush) {
            $parts[] = 'nopush';
        }

        return implode('; ', $parts);
    }
}
