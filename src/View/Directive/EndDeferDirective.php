<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @enddefer directive to close a deferred rendering block.
 *
 * Captures the deferred content and wraps it in a template element for
 * client-side slot replacement.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EndDeferDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'enddefer';
    }

    public function compile(string $expression): string
    {
        return <<<'PHP'
            <?php
            $__defer_content = ob_get_clean();
            echo '<template data-deferred-content>' . $__defer_content . '</template>';
            echo '</pulse-deferred>';
            unset($__defer_content);
            ?>
            PHP;
    }
}
