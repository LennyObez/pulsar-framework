<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;

/**
 * Compiles control flow directives that take an expression argument.
 *
 * Handles @if, @elseif, @foreach, @for, @while, @switch.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class ControlFlowDirective implements DirectiveInterface
{
    public function __construct(
        private string $directiveName,
        private string $phpKeyword,
    ) {}

    public function name(): string
    {
        return $this->directiveName;
    }

    public function compile(string $expression): string
    {
        return sprintf('<?php %s (%s): ?>', $this->phpKeyword, $expression);
    }
}
