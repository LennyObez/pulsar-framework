<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Pulsar\Api\Internal;

/**
 * Compiles the @endcache directive to close a fragment cache block.
 *
 * Captures buffered output and stores it in the cache with the configured TTL.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class EndCacheDirective implements DirectiveInterface
{
    public function name(): string
    {
        return 'endcache';
    }

    public function compile(string $expression): string
    {
        return <<<'PHP'
            <?php
            if (!$__cache_hit) {
                $__cache_output = ob_get_clean();
                echo $__cache_output;
                if (isset($__cache) && $__cache instanceof \Psr\SimpleCache\CacheInterface) {
                    $__cache->set($__cache_key, $__cache_output, $__cache_ttl);
                }
                unset($__cache_output);
            }
            unset($__cache_args, $__cache_key, $__cache_ttl, $__cache_hit);
            ?>
            PHP;
    }
}
