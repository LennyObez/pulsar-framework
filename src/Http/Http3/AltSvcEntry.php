<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * A single Alt-Svc entry for alternative service advertisement.
 */
#[Api(since: '1.0.0')]
final readonly class AltSvcEntry
{
    public function __construct(
        public string $protocol,
        public int $port,
        public int $maxAge = 86400,
        public bool $persist = false,
    ) {}

    /**
     * Generate the Alt-Svc entry value.
     *
     * Example: h3=":443"; ma=86400; persist=1
     */
    #[NoDiscard]
    public function toHeaderValue(): string
    {
        $value = sprintf('%s=":%d"; ma=%d', $this->protocol, $this->port, $this->maxAge);

        if ($this->persist) {
            $value .= '; persist=1';
        }

        return $value;
    }
}
