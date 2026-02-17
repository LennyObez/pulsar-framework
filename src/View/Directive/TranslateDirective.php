<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @t directive for translation with the framework's TranslatorInterface.
 *
 * Usage: @t('key', ['param' => $value])
 *
 * Uses the `$__translator` variable injected into templates. Falls back to
 * the `__()` helper function if no translator is available.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class TranslateDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 't';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        return sprintf(
            '<?php echo htmlspecialchars(isset($__translator) ? $__translator->translate(%s) : __(%s), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>',
            $expr,
            $expr,
        );
    }
}
