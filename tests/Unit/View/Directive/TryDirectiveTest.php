<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\TryDirective;

#[CoversClass(TryDirective::class)]
final class TryDirectiveTest extends TestCase
{
    #[Test]
    public function name_returns_try(): void
    {
        $directive = new TryDirective();

        self::assertSame('try', $directive->name());
    }

    #[Test]
    public function compile_opens_try_block_and_output_buffer(): void
    {
        $directive = new TryDirective();

        $output = $directive->compile('');

        self::assertStringContainsString('try {', $output);
        self::assertStringContainsString('ob_start()', $output);
    }

    #[Test]
    public function compile_ignores_expression(): void
    {
        $directive = new TryDirective();

        $with = $directive->compile('some expression');
        $without = $directive->compile('');

        self::assertSame($with, $without);
    }
}
