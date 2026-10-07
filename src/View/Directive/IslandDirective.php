<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @island directive for client-side partial hydration.
 *
 * Emits a custom element wrapper with serialized props for client-side hydration.
 * The component name maps to a registered island component on the client.
 *
 * Usage: @island('counter', props: ['count' => $count])
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class IslandDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'island';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        return sprintf(
            <<<'PHP'
                <?php
                $__island_args = [%s];
                $__island_name = $__island_args[0] ?? 'unknown';
                $__island_props = $__island_args['props'] ?? $__island_args[1] ?? [];
                $__island_json = json_encode($__island_props, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                echo '<pulse-island component="' . htmlspecialchars($__island_name, ENT_QUOTES, 'UTF-8') . '" data-props="' . htmlspecialchars($__island_json, ENT_QUOTES, 'UTF-8') . '"></pulse-island>';
                unset($__island_args, $__island_name, $__island_props, $__island_json);
                ?>
                PHP,
            $expr,
        );
    }
}
