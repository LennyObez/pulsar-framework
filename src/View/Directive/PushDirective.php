<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the `@push` directive for deferred content stacks.
 *
 * Pushes content onto a named stack that can be rendered later
 * with `@stack`. Useful for collecting scripts, styles, or meta tags
 * from child templates.
 *
 * Usage: `@push('scripts') ... @endpush`
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class PushDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'push';
    }

    public function compile(string $expression): string
    {
        $stack = trim($expression, " \t\n\r\0\x0B'\"");

        return sprintf(
            '<?php $__env->startPush(%s); ?>',
            var_export($stack, true),
        );
    }
}
