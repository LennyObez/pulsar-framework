<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

/**
 * Result of a DLP scan: what was found, where, and what action was taken.
 */
#[Api(since: '1.0.0')]
final readonly class DlpScanResult
{
    /**
     * @param list<DlpMatch> $matches
     */
    public function __construct(
        public bool $detected,
        public DlpAction $actionTaken,
        public array $matches,
        public string $redactedContent,
    ) {}

    public static function clean(string $content): self
    {
        return new self(
            detected: false,
            actionTaken: DlpAction::Alert,
            matches: [],
            redactedContent: $content,
        );
    }
}
