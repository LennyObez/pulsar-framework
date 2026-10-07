<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

/**
 * Classifies free text for sensitive data: the seam every DLP consumer and the AI
 * egress guard (ADR-0079) depend on, with {@see SensitivePatternRegistry} as its
 * shipped implementation.
 *
 * The result's {@see DlpScanStatus} says whether the content was examined. An
 * implementation that did not look must not return {@see DlpScanStatus::Completed}:
 * the egress guard reads anything else as a refusal.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface SensitiveDataClassifierInterface
{
    public function scan(string $content): DlpScanResult;
}
