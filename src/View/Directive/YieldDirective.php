<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @yield directive for rendering section content.
 *
 * Outputs the content of a named section, with an optional default.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class YieldDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'yield';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php echo $__env->yieldSection(%s); ?>', trim($expression));
    }
}
