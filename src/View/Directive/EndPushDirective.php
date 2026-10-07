<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @endpush directive to close a push block.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EndPushDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'endpush';
    }

    public function compile(string $expression): string
    {
        return '<?php $__env->stopPush(); ?>';
    }
}
