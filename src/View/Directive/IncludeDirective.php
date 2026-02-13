<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @include directive for partial template inclusion.
 *
 * Renders another template inline with optional scoped data.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class IncludeDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'include';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php echo $__env->renderInclude(%s); ?>', trim($expression));
    }
}
