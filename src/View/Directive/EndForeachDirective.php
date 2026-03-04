<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Closes @foreach and restores the parent $loop variable.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EndForeachDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'endforeach';
    }

    public function compile(string $expression): string
    {
        return '<?php endforeach; $loop = $__loopParent; ?>';
    }
}
