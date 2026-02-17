<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function trim;

/**
 * Compiles the @typed directive for type-safe template variables.
 *
 * Usage: @typed(['user' => User::class, 'count' => 'int'])
 *
 * Generates runtime type checks at template entry. Variables that don't match
 * their declared types throw a ViewException with a clear diagnostic message.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class TypedDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'typed';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        return <<<PHP
            <?php
            foreach ({$expr} as \$__typed_name => \$__typed_type) {
                if (!isset(\$\$__typed_name) && \$__typed_type !== 'null' && \$__typed_type !== '?mixed') {
                    throw \Pulsar\View\ViewException::typedTemplateViolation(
                        __FILE__, \$__typed_name, \$__typed_type, 'undefined',
                    );
                }
                if (isset(\$\$__typed_name)) {
                    \$__typed_actual = \$\$__typed_name;
                    \$__typed_valid = match(\$__typed_type) {
                        'int', 'integer' => is_int(\$__typed_actual),
                        'float', 'double' => is_float(\$__typed_actual) || is_int(\$__typed_actual),
                        'string' => is_string(\$__typed_actual),
                        'bool', 'boolean' => is_bool(\$__typed_actual),
                        'array' => is_array(\$__typed_actual),
                        'null' => \$__typed_actual === null,
                        'callable' => is_callable(\$__typed_actual),
                        'iterable' => is_iterable(\$__typed_actual),
                        'mixed' => true,
                        default => \$__typed_actual instanceof \$__typed_type,
                    };
                    if (!\$__typed_valid) {
                        throw \Pulsar\View\ViewException::typedTemplateViolation(
                            __FILE__, \$__typed_name, \$__typed_type, get_debug_type(\$__typed_actual),
                        );
                    }
                }
            }
            unset(\$__typed_name, \$__typed_type, \$__typed_actual, \$__typed_valid);
            ?>
            PHP;
    }
}
