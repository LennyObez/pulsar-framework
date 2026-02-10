<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @can directive for authorization checks.
 *
 * Renders content only if the current user is authorized for the given ability.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CanDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'can';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php if ($__auth->can(%s)): ?>', trim($expression));
    }
}
