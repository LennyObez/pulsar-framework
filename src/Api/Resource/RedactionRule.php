<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

use function hash;
use function mb_substr;
use function str_repeat;

/**
 * Rules for masking, truncating, or hashing field values when the requester
 * has partial access to a field.
 *
 * Applied when a requester's clearance level is insufficient for full access
 * but sufficient for redacted access.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RedactionRule
{
    /**
     * @param DataClassification $appliesAbove Classification level above which this rule applies
     * @param RedactionStrategy $strategy The redaction strategy to use
     * @param string $maskChar Character used for masking (only for Mask strategy)
     * @param int $truncateLength Maximum visible length (only for Truncate strategy)
     * @param string $hashAlgorithm Algorithm for hashing (only for Hash strategy)
     */
    public function __construct(
        public DataClassification $appliesAbove,
        public RedactionStrategy $strategy,
        public string $maskChar = '*',
        public int $truncateLength = 4,
        public string $hashAlgorithm = 'sha256',
    ) {}

    /**
     * Apply the redaction rule to a field value.
     *
     * @return string|null The redacted value, or null if the field should be omitted
     */
    #[NoDiscard]
    public function apply(string $value): ?string
    {
        return match ($this->strategy) {
            RedactionStrategy::Mask => str_repeat($this->maskChar, 3),
            RedactionStrategy::Truncate => mb_substr($value, 0, $this->truncateLength) . '...',
            RedactionStrategy::Hash => hash($this->hashAlgorithm, $value),
            RedactionStrategy::Omit => null,
        };
    }
}
