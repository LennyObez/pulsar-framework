<?php

declare(strict_types=1);

namespace Pulsar\Api;

/**
 * Represents a single backward-compatibility break found by the detector.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BcBreak
{
    public function __construct(
        public BcBreakType $type,
        public string $symbol,
        public string $message,
        public BcBreakSeverity $severity,
        public string $stability,
    ) {}
}
