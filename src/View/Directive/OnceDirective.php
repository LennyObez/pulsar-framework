<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @once directive for one-time rendering.
 *
 * Renders the enclosed block only once per request, even if
 * the template is rendered multiple times (e.g. in a loop).
 *
 * Usage:
 *   @once
 *       <script src="/app.js"></script>
 *   @endonce
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class OnceDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'once';
    }

    public function compile(string $expression): string
    {
        return '<?php if (!isset($__once_blocks)) { $__once_blocks = []; } '
            . '$__once_id = __FILE__ . ":" . __LINE__; '
            . 'if (!isset($__once_blocks[$__once_id])): '
            . '$__once_blocks[$__once_id] = true; ?>';
    }
}
