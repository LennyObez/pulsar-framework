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
        // Delegate to the shared $__env so the once-registry survives @include
        // boundaries (once per request) instead of a template-local variable that
        // is recreated on every isolated template execution. @endonce emits the
        // matching `endif`.
        return '<?php if ($__env->renderOnce(__FILE__ . ":" . __LINE__)): ?>';
    }
}
