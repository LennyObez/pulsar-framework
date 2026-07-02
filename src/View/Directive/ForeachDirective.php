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
 *
 * The compiled code delegates iterable validation to PHP's native
 * foreach so that template authors see the canonical error message
 * (TypeError: foreach() argument must be of type Traversable|array)
 * at the expected location rather than a cryptic count/iterator_to_array
 * error during $loop initialization.
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

        // Pre-compute the iterable count for $loop->count without exhausting
        // Generators or breaking the subsequent foreach:
        //  - Arrays and Countable: count() is O(1); iterate the original
        //  - Traversable but not Countable (Generator, Iterator): materialize
        //    once, PRESERVING KEYS (preserve_keys: true), so a keyed iteration
        //    (`as $k => $v`) sees the real keys rather than 0,1,2,... and count()
        //    and foreach walk the same data. Duplicate keys follow PHP array
        //    semantics (last value wins), an unavoidable consequence of
        //    materializing into an array to size $loop.
        //  - Anything else: delegate to the native foreach below for the
        //    canonical TypeError, with count = 0 so $loop is still well-formed
        return '<?php'
            . ' $__loopParent = $loop ?? null;'
            . ' $__loopDepth = ($__loopParent instanceof \Pulsar\View\Engine\LoopVariable) ? $__loopParent->depth + 1 : 1;'
            . ' $__loopItems = ' . $iterable . ';'
            . ' if (is_countable($__loopItems)) { $__loopCount = count($__loopItems); }'
            . ' elseif ($__loopItems instanceof \Traversable) { $__loopItems = iterator_to_array($__loopItems, true); $__loopCount = count($__loopItems); }'
            . ' else { $__loopCount = 0; }'
            . ' $loop = new \Pulsar\View\Engine\LoopVariable($__loopCount, $__loopDepth, $__loopParent instanceof \Pulsar\View\Engine\LoopVariable ? $__loopParent : null);'
            . ' foreach (' . $this->rewriteIterable($expression) . '):'
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

    /**
     * Replace the iterable in the foreach expression with $__loopItems.
     *
     * This ensures Generators and other Traversables that were materialized
     * during count computation are iterated from the materialized array,
     * not exhausted by a second evaluation of the original expression.
     */
    private function rewriteIterable(string $expression): string
    {
        $expr = trim($expression);

        if (preg_match('/\A(.+?)(\s+as\s+.+)\z/is', $expr, $matches) === 1) {
            return '$__loopItems' . $matches[2];
        }

        return $expression;
    }
}
