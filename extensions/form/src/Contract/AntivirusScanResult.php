<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Contract;

use Pulsar\Api\Api;

/**
 * Result of an antivirus scan.
 */
#[Api(since: '1.0.0')]
final readonly class AntivirusScanResult
{
    public function __construct(
        public bool $clean,
        public string $threat,
    ) {}

    public static function clean(): self
    {
        return new self(clean: true, threat: '');
    }

    public static function infected(string $threat): self
    {
        return new self(clean: false, threat: $threat);
    }
}
