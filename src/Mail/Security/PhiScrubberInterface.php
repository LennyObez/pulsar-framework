<?php

declare(strict_types=1);

namespace Pulsar\Mail\Security;

use Pulsar\Api\Api;

/**
 * Detects and scrubs Protected Health Information (PHI) from email metadata.
 *
 * Used in HIPAA-compliant mail pipelines to ensure no PHI leaks
 * through subject lines, preheaders, or preview text.
 */
#[Api(since: '1.0.0')]
interface PhiScrubberInterface
{
    /**
     * Scrub PHI from a named field value.
     *
     * Returns the value with all detected PHI patterns replaced by "[REDACTED]".
     */
    public function scrub(string $field, string $value): string;

    /**
     * Check whether a value contains any PHI patterns.
     */
    public function containsPhi(string $value): bool;
}
