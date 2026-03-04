<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @try directive to open an error boundary block.
 *
 * Wraps template content in a try/catch that renders a fallback
 * message instead of crashing on errors.
 *
 * Usage:
 *   {@}try
 *     {{ $riskyContent }}
 *   {@}catch('Failed to render content')
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class TryDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'try';
    }

    public function compile(string $expression): string
    {
        return '<?php try { ob_start(); ?>';
    }
}
