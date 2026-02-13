<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;

/**
 * Compiles the @case directive for switch statements.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CaseDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'case';
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php case (%s): ?>', $expression);
    }
}
