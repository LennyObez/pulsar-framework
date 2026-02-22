<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use NoDiscard;
use Pulsar\Api\Api;

use function count;
use function in_array;

/**
 * Immutable value object representing a set of hashed recovery codes.
 */
#[Api(since: '1.0.0')]
final readonly class RecoveryCodeSet
{
    /**
     * @param string $setId Unique identifier for this code set (128-bit hex)
     * @param list<string> $codeHashes keyed BLAKE2b hashes of each code
     * @param list<int> $usedIndices Indices of consumed codes
     * @param int $algorithmVersion 1 = 32-bit legacy, 2 = 64-bit
     * @param int $createdAt Unix timestamp of creation
     */
    public function __construct(
        public string $setId,
        public array $codeHashes,
        public array $usedIndices,
        public int $algorithmVersion,
        public int $createdAt,
    ) {}

    public function isUsed(int $index): bool
    {
        return in_array($index, $this->usedIndices, true);
    }

    #[NoDiscard]
    public function withUsedIndex(int $index): self
    {
        if ($this->isUsed($index)) {
            return $this;
        }

        return new self(
            $this->setId,
            $this->codeHashes,
            [...$this->usedIndices, $index],
            $this->algorithmVersion,
            $this->createdAt,
        );
    }

    public function remainingCount(): int
    {
        return count($this->codeHashes) - count($this->usedIndices);
    }
}
