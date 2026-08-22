<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles `@cloaktel('32495733136', [attrs])` to an anti-scraping phone link.
 *
 * The number is emitted as a base64-encoded `data-n` attribute — no dialable
 * string in the HTML — and the bundled `contact-cloak.js` reassembles the `tel:`
 * link and visible text on load. With JavaScript off the element is readable
 * fallback text, never a broken link.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CloakTelDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'cloaktel';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo \Pulsar\View\ContactCloak::tel(%s); ?>',
            trim($expression),
        );
    }
}
