<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @tRaw directive for unescaped translation output.
 *
 * Usage: @tRaw('key', ['param' => $value])
 *
 * Unlike @t(), this directive does NOT HTML-escape the output. Use it
 * for translations that contain trusted HTML (links, formatting)
 * marked with `html_safe => true` in the translation catalog.
 *
 * Security: only use @tRaw with translation keys whose content is
 * controlled by the application, never with user-supplied input.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class TranslateRawDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'tRaw';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        return sprintf(
            '<?php echo isset($__translator) ? $__translator->translate(%s) : __(%s); ?>',
            $expr,
            $expr,
        );
    }
}
