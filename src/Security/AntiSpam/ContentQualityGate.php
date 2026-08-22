<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

use function mb_strlen;
use function preg_match_all;
use function sprintf;

/**
 * Enforces minimum content quality standards.
 *
 * Rejects submissions that are:
 * - Too short (below minimum character count)
 * - Predominantly uppercase (shouting / spam indicator)
 * - Full of repeated characters (keyboard mashing)
 */
#[Internal(reason: 'Use ContentQualityGateInterface')]
final readonly class ContentQualityGate implements ContentQualityGateInterface
{
    public function __construct(
        private int $minLength = 10,
        private float $maxUppercaseRatio = 0.8,
        private float $maxRepeatedCharRatio = 0.5,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'content_quality';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        $body = $context->body;
        $bodyLength = mb_strlen($body);

        // Minimum length check
        if ($bodyLength < $this->minLength) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                20,
                sprintf('Content too short: %d characters (minimum %d)', $bodyLength, $this->minLength),
            );
        }

        // Uppercase ratio check (only on alphabetic characters)
        if ($this->exceedsUppercaseRatio($body)) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                25,
                sprintf('Content is >%.0f%% uppercase', $this->maxUppercaseRatio * 100),
            );
        }

        // Repeated character ratio check
        if ($this->exceedsRepeatedCharRatio($body, $bodyLength)) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                25,
                sprintf('Content has >%.0f%% repeated characters', $this->maxRepeatedCharRatio * 100),
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }

    private function exceedsUppercaseRatio(string $body): bool
    {
        // Only consider characters that have case distinction (Lu or Ll)
        $casedLetterCount = preg_match_all('/[\p{Lu}\p{Ll}]/u', $body);

        if ($casedLetterCount < 5) {
            return false;
        }

        // Count uppercase letters directly
        $uppercaseCount = preg_match_all('/\p{Lu}/u', $body);

        return ($uppercaseCount / $casedLetterCount) > $this->maxUppercaseRatio;
    }

    private function exceedsRepeatedCharRatio(string $body, int $bodyLength): bool
    {
        // Count characters that are part of runs of 3+ identical chars
        $repeatedCount = 0;

        if (preg_match_all('/(.)\1{2,}/u', $body, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $repeatedCount += mb_strlen($match[0]);
            }
        }

        return ($repeatedCount / $bodyLength) > $this->maxRepeatedCharRatio;
    }
}
