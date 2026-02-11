<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Helper;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Renders a skip-to-main-content link for keyboard navigation.
 *
 * The link is visually hidden by default (via pui-skip-link) and becomes
 * visible on focus, allowing keyboard users to bypass repetitive navigation.
 */
#[Api(since: '1.0.0')]
final readonly class SkipNavigation
{
    public function render(string $targetId = 'main-content'): string
    {
        $escapedId = htmlspecialchars($targetId, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return sprintf(
            '<a class="pui-skip-link" href="#%s">Skip to main content</a>',
            $escapedId,
        );
    }
}
