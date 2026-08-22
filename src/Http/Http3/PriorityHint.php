<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * A single resource priority hint.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PriorityHint
{
    public function __construct(
        public string $href,
        public string $as,
        public FetchPriority $priority,
    ) {}

    /**
     * Generate the Link header value with fetchpriority parameter.
     *
     * Example: </img/hero.jpg>; rel=preload; as=image; fetchpriority=high
     */
    #[NoDiscard]
    public function toLinkHeaderValue(): string
    {
        return sprintf(
            '<%s>; rel=preload; as=%s; fetchpriority=%s',
            $this->href,
            $this->as,
            $this->priority->value,
        );
    }

    /**
     * Generate the HTML fetchpriority attribute value.
     */
    #[NoDiscard]
    public function toHtmlAttribute(): string
    {
        return $this->priority->value;
    }
}
