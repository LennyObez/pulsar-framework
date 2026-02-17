<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @defer directive for progressive rendering.
 *
 * Renders a lightweight placeholder initially, then streams the real content
 * later via chunked transfer encoding. Uses a custom element wrapper with
 * a unique slot ID.
 *
 * Usage: @defer('section-name')
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class DeferDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'defer';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        if ($expr === '') {
            $expr = "'deferred-' . (\$__defer_id = (\$__defer_id ?? 0) + 1)";
        }

        return sprintf(
            '<?php $__defer_slot = %s; echo \'<pulse-deferred data-slot="\' . htmlspecialchars($__defer_slot, ENT_QUOTES, \'UTF-8\') . \'">\'; ob_start(); ?>',
            $expr,
        );
    }
}
