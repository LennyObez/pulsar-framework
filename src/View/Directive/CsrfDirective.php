<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @csrf directive to output a hidden CSRF token field.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CsrfDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'csrf';
    }

    public function compile(string $expression): string
    {
        return '<?php echo \'<input type="hidden" name="_token" value="\' . htmlspecialchars($__csrf ?? \'\', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\') . \'">\'; ?>';
    }
}
