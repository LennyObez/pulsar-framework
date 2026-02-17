<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

use function sprintf;
use function trim;

/**
 * Compiles the @cache directive for fragment caching.
 *
 * Usage: @cache('key', ttl: 300)
 *
 * Uses the `$__cache` PSR-16 SimpleCache interface injected into templates.
 * On hit, outputs cached content. On miss, starts output buffering for capture.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class CacheDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'cache';
    }

    public function compile(string $expression): string
    {
        $expr = trim($expression);

        return sprintf(
            <<<'PHP'
                <?php
                $__cache_args = [%s];
                $__cache_key = 'pulse_fragment_' . ($__cache_args[0] ?? '');
                $__cache_ttl = $__cache_args['ttl'] ?? $__cache_args[1] ?? null;
                $__cache_hit = false;
                if (isset($__cache) && $__cache instanceof \Psr\SimpleCache\CacheInterface) {
                    $__cache_content = $__cache->get($__cache_key);
                    if ($__cache_content !== null) {
                        echo $__cache_content;
                        $__cache_hit = true;
                    }
                }
                if (!$__cache_hit) {
                    ob_start();
                ?>
                PHP,
            $expr,
        );
    }
}
