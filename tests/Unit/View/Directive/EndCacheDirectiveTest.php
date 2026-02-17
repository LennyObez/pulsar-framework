<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EndCacheDirective;

#[CoversClass(EndCacheDirective::class)]
final class EndCacheDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_endcache(): void
    {
        $directive = new EndCacheDirective();

        self::assertSame('endcache', $directive->name());
    }

    #[Test]
    public function compile_captures_buffer_and_stores_in_cache(): void
    {
        $directive = new EndCacheDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('ob_get_clean', $output);
        self::assertStringContainsString('CacheInterface', $output);
        self::assertStringContainsString('__cache_key', $output);
        self::assertStringContainsString('__cache_ttl', $output);
        self::assertStringContainsString('__cache->set', $output);
    }

    #[Test]
    public function compile_cleans_up_variables(): void
    {
        $directive = new EndCacheDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('unset($__cache_args', $output);
        self::assertStringContainsString('unset($__cache_output)', $output);
    }

    #[Test]
    public function compile_ignores_expression(): void
    {
        $directive = new EndCacheDirective();

        $with = $directive->compile('ignored');
        $without = $directive->compile('');

        self::assertSame($with, $without);
    }
}
