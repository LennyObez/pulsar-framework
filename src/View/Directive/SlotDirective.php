<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @slot directive for named slot content.
 *
 * Opens a named slot block within a component.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class SlotDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'slot';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php $__env->startSlot(%s); ?>', trim($expression));
    }
}
