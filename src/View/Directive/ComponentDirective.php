<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @component directive for component rendering.
 *
 * Opens a component block that captures content for the default slot.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class ComponentDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'component';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php $__env->startComponent(%s); ?>', trim($expression));
    }
}
