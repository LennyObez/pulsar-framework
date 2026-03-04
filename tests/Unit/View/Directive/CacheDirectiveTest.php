<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\CacheDirective;

#[CoversClass(CacheDirective::class)]
final class CacheDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_cache(): void
    {
        $directive = new CacheDirective();

        self::assertSame('cache', $directive->name());
    }

    #[Test]
    public function compile_produces_cache_lookup_and_ob_start(): void
    {
        $directive = new CacheDirective();

        $output = $directive->compile("'sidebar', ttl: 300");

        self::assertStringContainsString('pulse_fragment_', $output);
        self::assertStringContainsString('CacheInterface', $output);
        self::assertStringContainsString('ob_start', $output);
        self::assertStringContainsString('__cache_hit', $output);
        self::assertStringContainsString('__cache_ttl', $output);
    }

    #[Test]
    public function compile_embeds_expression_in_cache_args(): void
    {
        $directive = new CacheDirective();

        $output = $directive->compile("'my-key'");

        self::assertStringContainsString("'my-key'", $output);
    }

    #[Test]
    public function compile_trims_expression_whitespace(): void
    {
        $directive = new CacheDirective();

        $withSpaces = $directive->compile("   'key'   ");
        $withoutSpaces = $directive->compile("'key'");

        self::assertSame($withSpaces, $withoutSpaces);
    }
}
