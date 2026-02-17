<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @form directive for DTO-based form binding.
 *
 * Auto-generates form fields from a DTO's typed properties. Reads the DTO's
 * public properties via reflection and generates appropriate HTML inputs.
 *
 * Usage: {@}form($dto)
 *        {@}form($dto, ['action' => '/submit', 'method' => 'POST'])
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class FormDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'form';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        return sprintf(
            <<<'PHP'
                <?php
                $__form_args = [%s];
                $__form_dto = $__form_args[0] ?? null;
                $__form_opts = $__form_args[1] ?? [];
                $__form_action = $__form_opts['action'] ?? '';
                $__form_method = strtoupper($__form_opts['method'] ?? 'POST');
                $__form_html_method = in_array($__form_method, ['GET', 'POST'], true) ? $__form_method : 'POST';
                echo '<form action="' . htmlspecialchars($__form_action, ENT_QUOTES, 'UTF-8') . '" method="' . $__form_html_method . '">';
                if ($__form_method !== 'GET' && $__form_method !== 'POST') {
                    echo '<input type="hidden" name="_method" value="' . htmlspecialchars($__form_method, ENT_QUOTES, 'UTF-8') . '">';
                }
                if ($__form_method !== 'GET' && isset($__csrf)) {
                    echo '<input type="hidden" name="_token" value="' . htmlspecialchars($__csrf, ENT_QUOTES, 'UTF-8') . '">';
                }
                if (is_object($__form_dto)) {
                    $__form_ref = new \ReflectionClass($__form_dto);
                    foreach ($__form_ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $__form_prop) {
                        $__form_name = $__form_prop->getName();
                        $__form_value = $__form_prop->isInitialized($__form_dto) ? (string) $__form_prop->getValue($__form_dto) : '';
                        $__form_type_obj = $__form_prop->getType();
                        $__form_type = $__form_type_obj instanceof \ReflectionNamedType ? $__form_type_obj->getName() : 'string';
                        $__form_input_type = match($__form_type) {
                            'int', 'float' => 'number',
                            'bool' => 'checkbox',
                            default => 'text',
                        };
                        echo '<div class="pulse-form-field">';
                        echo '<label for="' . htmlspecialchars($__form_name, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $__form_name)), ENT_QUOTES, 'UTF-8') . '</label>';
                        if ($__form_input_type === 'checkbox') {
                            echo '<input type="checkbox" id="' . htmlspecialchars($__form_name, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($__form_name, ENT_QUOTES, 'UTF-8') . '" value="1"' . ($__form_value ? ' checked' : '') . '>';
                        } else {
                            echo '<input type="' . $__form_input_type . '" id="' . htmlspecialchars($__form_name, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($__form_name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($__form_value, ENT_QUOTES, 'UTF-8') . '">';
                        }
                        echo '</div>';
                    }
                }
                ?>
                PHP,
            $expr,
        );
    }
}
