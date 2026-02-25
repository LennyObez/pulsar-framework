<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;

use function is_string;
use function trim;

/**
 * Detects spam by checking if a hidden honeypot field has been filled.
 *
 * Bots typically fill all form fields, including hidden ones.
 * Legitimate users never see or interact with the honeypot field.
 *
 * @psalm-api Aggregated by SpamScorer through the SpamDetectorInterface contract;
 *            resolved from the DI container, not instantiated by name.
 */
#[Internal(reason: 'Spam detector; use SpamDetectorInterface')]
final readonly class HoneypotDetector implements SpamDetectorInterface
{
    public function __construct(
        private string $fieldName = '_hp_field',
    ) {}

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        // Read from $meta where the controller extracts _-prefixed fields,
        // with a fallback to $data for direct programmatic usage.
        $value = $meta[$this->fieldName] ?? $data[$this->fieldName] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return new SpamResult(true, 10.0, 'Honeypot field filled');
        }

        return new SpamResult(false, 0.0, null);
    }
}
