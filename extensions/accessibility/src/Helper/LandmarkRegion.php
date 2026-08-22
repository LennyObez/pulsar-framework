<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Helper;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Renders ARIA landmark wrapper elements for page structure.
 *
 * Each method wraps content in the appropriate semantic HTML element
 * with ARIA attributes for assistive technology navigation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LandmarkRegion
{
    public function main(string $content, string $label = ''): string
    {
        if ($label === '') {
            return sprintf('<main>%s</main>', $content);
        }

        return sprintf(
            '<main aria-label="%s">%s</main>',
            htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }

    public function navigation(string $content, string $label): string
    {
        return sprintf(
            '<nav aria-label="%s">%s</nav>',
            htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }

    public function banner(string $content): string
    {
        return sprintf('<header role="banner">%s</header>', $content);
    }

    public function contentinfo(string $content): string
    {
        return sprintf('<footer role="contentinfo">%s</footer>', $content);
    }

    public function complementary(string $content, string $label): string
    {
        return sprintf(
            '<aside aria-label="%s">%s</aside>',
            htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }

    public function region(string $content, string $label): string
    {
        return sprintf(
            '<section role="region" aria-label="%s">%s</section>',
            htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }
}
