<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @section directive for block definition.
 *
 * Opens a named section that can be yielded in the parent layout.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class SectionDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'section';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php $__env->startSection(%s); ?>', trim($expression));
    }
}
