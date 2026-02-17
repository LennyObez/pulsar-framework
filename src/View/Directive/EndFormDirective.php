<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @endform directive to close a DTO form binding block.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EndFormDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'endform';
    }

    public function compile(string $expression): string
    {
        return <<<'PHP'
            <?php
            echo '</form>';
            unset($__form_args, $__form_dto, $__form_opts, $__form_action, $__form_method, $__form_html_method, $__form_ref, $__form_prop, $__form_name, $__form_value, $__form_type_obj, $__form_type, $__form_input_type);
            ?>
            PHP;
    }
}
