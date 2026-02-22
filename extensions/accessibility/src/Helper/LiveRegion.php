<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Helper;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Renders ARIA live region containers for dynamic content updates.
 *
 * Live regions announce content changes to screen readers without
 * requiring the user to navigate to the updated area.
 */
#[Api(since: '1.0.0')]
final readonly class LiveRegion
{
    public function polite(string $id, string $content = ''): string
    {
        return sprintf(
            '<div id="%s" aria-live="polite" aria-atomic="true">%s</div>',
            htmlspecialchars($id, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }

    public function assertive(string $id, string $content = ''): string
    {
        return sprintf(
            '<div id="%s" aria-live="assertive" aria-atomic="true">%s</div>',
            htmlspecialchars($id, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }

    public function status(string $id, string $content = ''): string
    {
        return sprintf(
            '<div id="%s" role="status" aria-live="polite" aria-atomic="true">%s</div>',
            htmlspecialchars($id, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }

    public function log(string $id, string $content = ''): string
    {
        return sprintf(
            '<div id="%s" role="log" aria-live="polite" aria-relevant="additions">%s</div>',
            htmlspecialchars($id, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $content,
        );
    }
}
