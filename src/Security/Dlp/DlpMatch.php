<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

/**
 * Represents a single sensitive data match found by the DLP engine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DlpMatch
{
    public function __construct(
        public SensitiveDataType $type,
        public string $pattern,
        public int $offset,
        public int $length,
        public string $maskedValue,
    ) {}
}
