<?php

declare(strict_types=1);

namespace Pulsar\Security\ApiSigning;

use Pulsar\Api\Api;

/**
 * Components extracted from or prepared for a signed request.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SignedRequestComponents
{
    public function __construct(
        public string $method,
        public string $path,
        public string $timestamp,
        public string $bodyHash,
        public string $keyId,
    ) {}

    /**
     * Build the canonical string to sign.
     *
     * Format: METHOD\nPATH\nTIMESTAMP\nBODY_HASH
     */
    public function canonicalString(): string
    {
        return $this->method . "\n"
            . $this->path . "\n"
            . $this->timestamp . "\n"
            . $this->bodyHash;
    }
}
