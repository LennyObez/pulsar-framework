<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the `@stack` directive to render a named content stack.
 *
 * Outputs all content pushed to the named stack via `@push`.
 *
 * Usage: `@stack('scripts')`
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class StackDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'stack';
    }

    public function compile(string $expression): string
    {
        $stack = trim($expression, " \t\n\r\0\x0B'\"");

        return sprintf(
            '<?php echo implode("", $__stacks[%s] ?? []); ?>',
            var_export($stack, true),
        );
    }
}
