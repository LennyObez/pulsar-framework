<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function preg_match;
use function trim;

/**
 * Compiles @foreach with $loop variable injection.
 *
 * The compiled output creates a LoopVariable, starts the foreach, and
 * calls $loop->step() at the top of each iteration. Nested @foreach
 * blocks stack via $loop->parent.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class ForeachDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'foreach';
    }

    public function compile(string $expression): string
    {
        $iterable = $this->extractIterable($expression);

        return '<?php'
            . ' $__loopParent = $loop ?? null;'
            . ' $__loopDepth = ($__loopParent instanceof \Pulsar\View\Engine\LoopVariable) ? $__loopParent->depth + 1 : 1;'
            . ' $__loopItems = ' . $iterable . ';'
            . ' $loop = new \Pulsar\View\Engine\LoopVariable('
            . 'is_countable($__loopItems) ? count($__loopItems) : count(iterator_to_array($__loopItems, false)),'
            . ' $__loopDepth,'
            . ' $__loopParent instanceof \Pulsar\View\Engine\LoopVariable ? $__loopParent : null);'
            . ' foreach (' . $expression . '):'
            . ' $loop->step(); ?>';
    }

    /**
     * Extract the iterable expression (before " as ") from a foreach argument.
     */
    private function extractIterable(string $expression): string
    {
        $expr = trim($expression);

        if (preg_match('/\A(.+?)\s+as\s+/i', $expr, $matches) === 1) {
            return trim($matches[1]);
        }

        return $expr;
    }
}
