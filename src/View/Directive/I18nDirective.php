<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @i18n directive for translated strings.
 *
 * Integrates with the __() translation helper from the i18n module.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class I18nDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'i18n';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo htmlspecialchars(__(%s)); ?>',
            trim($expression),
        );
    }
}
