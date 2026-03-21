<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms\SpamDetection;

use Pulsar\Api\Api;

/**
 * Contract for individual spam detection strategies.
 *
 * @psalm-api Public extension contract; implementations registered with
 *            SpamScorer and aggregated into a combined score.
 * @api
 */
#[Api(since: '1.0.0')]
interface SpamDetectorInterface
{
    /**
     * Analyze form data and request metadata for spam signals.
     *
     * @param array<string, mixed> $data  Form field data
     * @param array<string, mixed> $meta  Request metadata (IP, timing, etc.)
     */
    public function detect(array $data, array $meta): SpamResult;
}
