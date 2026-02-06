<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * A directive that always compiles to the same static output.
 *
 * Used for closing tags like @endif, @endforeach, @else, etc.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class SimpleDirective implements DirectiveInterface
{
    public function __construct(
        private string $directiveName,
        private string $output,
    ) {}

    public function name(): string
    {
        return $this->directiveName;
    }

    public function compile(string $expression): string
    {
        return $this->output;
    }
}
