<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @extends directive for template inheritance.
 *
 * Sets the parent layout that this template extends.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class ExtendsDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'extends';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php $__env->setParent(%s); ?>', trim($expression));
    }
}
