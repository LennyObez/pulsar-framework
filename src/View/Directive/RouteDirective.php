<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @route directive to a localized route URL.
 *
 * `@route('development')` or `@route('blog/post', ['slug' => $slug], 'fr')`
 * delegates to the route() helper, emitting the locale-translated, prefixed
 * URL for the key. Output is HTML-escaped for safe use in href/src attributes.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class RouteDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'route';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo htmlspecialchars(route(%s), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>',
            trim($expression),
        );
    }
}
