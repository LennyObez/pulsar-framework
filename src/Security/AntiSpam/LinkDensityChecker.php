<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;

use function mb_strlen;
use function preg_match_all;
use function sprintf;

/**
 * Rejects submissions where URLs make up a disproportionate share of the body.
 *
 * Calculates the ratio of characters inside URLs to total body length.
 * A ratio above the configurable threshold indicates likely link spam.
 */
#[Internal(reason: 'Use LinkDensityCheckerInterface')]
final readonly class LinkDensityChecker implements LinkDensityCheckerInterface
{
    public function __construct(
        private float $maxDensity = 0.3,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'link_density';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        $bodyLength = mb_strlen($context->body);

        if ($bodyLength === 0) {
            return AntiSpamCheckResult::pass($this->name());
        }

        $urlCharCount = 0;

        if (preg_match_all('#https?://[^\s)>\]]+#i', $context->body, $matches) > 0) {
            foreach ($matches[0] as $url) {
                $urlCharCount += mb_strlen($url);
            }
        }

        $density = $urlCharCount / $bodyLength;

        if ($density > $this->maxDensity) {
            return AntiSpamCheckResult::fail(
                $this->name(),
                30,
                sprintf(
                    'Link density %.0f%% exceeds maximum %.0f%%',
                    $density * 100,
                    $this->maxDensity * 100,
                ),
            );
        }

        return AntiSpamCheckResult::pass($this->name());
    }
}
