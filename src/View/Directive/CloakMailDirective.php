<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles `@cloakmail('user', 'domain', [attrs])` to an anti-scraping e-mail
 * link.
 *
 * The address is emitted as base64-encoded `data-u` / `data-d` attributes — no
 * literal `user@domain` in the HTML — and the bundled `contact-cloak.js`
 * reassembles the `mailto:` link and visible text on load. With JavaScript off
 * the element is readable fallback text, never a broken link.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CloakMailDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'cloakmail';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo \Pulsar\View\ContactCloak::mail(%s); ?>',
            trim($expression),
        );
    }
}
