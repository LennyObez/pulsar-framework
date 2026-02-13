<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @method directive to output a hidden method spoofing field.
 *
 * Used for PUT, PATCH, DELETE forms that need method spoofing.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class MethodDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'method';
    }

    public function compile(string $expression): string
    {
        return sprintf(
            '<?php echo \'<input type="hidden" name="_method" value="\' . htmlspecialchars(%s, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\') . \'">\'; ?>',
            trim($expression),
        );
    }
}
