<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * A single finding from a policy analyzer.
 */
#[Api(since: '1.0.0')]
final readonly class AnalyzerFinding
{
    /**
     * @param string $field The field path that triggered the finding
     * @param string $pattern The matched pattern or evidence description
     */
    public function __construct(
        public FindingSeverity $severity,
        public float $confidence,
        public string $field,
        public string $pattern,
        public string $recommendation,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Confidence must be between 0.0 and 1.0, got %f.', $confidence),
            );
        }
    }
}
