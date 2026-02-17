<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\EndPushDirective;
use Pulsar\View\Directive\PushDirective;
use Pulsar\View\Directive\StackDirective;

final class PushStackDirectiveTest extends TestCase
{
    // ── PushDirective ─────────────────────────────────────────────────

    #[Test]
    public function pushDirectiveNameIsPush(): void
    {
        self::assertSame('push', new PushDirective()->name());
    }

    #[Test]
    public function pushCompileStartsOutputBuffer(): void
    {
        $result = new PushDirective()->compile("'scripts'");

        self::assertStringContainsString('ob_start()', $result);
        self::assertStringContainsString('$__current_stack', $result);
        self::assertStringContainsString('scripts', $result);
    }

    #[Test]
    public function pushCompileInitializesStacksArray(): void
    {
        $result = new PushDirective()->compile("'styles'");

        self::assertStringContainsString('$__stacks', $result);
    }

    #[Test]
    public function pushCompileHandlesQuotedAndUnquotedNames(): void
    {
        $withQuotes = new PushDirective()->compile("'scripts'");
        $withDoubleQuotes = new PushDirective()->compile('"scripts"');

        self::assertStringContainsString('scripts', $withQuotes);
        self::assertStringContainsString('scripts', $withDoubleQuotes);
    }

    // ── EndPushDirective ──────────────────────────────────────────────

    #[Test]
    public function endPushDirectiveNameIsEndpush(): void
    {
        self::assertSame('endpush', new EndPushDirective()->name());
    }

    #[Test]
    public function endPushCompilesBufferCapture(): void
    {
        $result = new EndPushDirective()->compile('');

        self::assertStringContainsString('ob_get_clean()', $result);
        self::assertStringContainsString('$__stacks[$__current_stack]', $result);
    }

    // ── StackDirective ────────────────────────────────────────────────

    #[Test]
    public function stackDirectiveNameIsStack(): void
    {
        self::assertSame('stack', new StackDirective()->name());
    }

    #[Test]
    public function stackCompileRendersNamedStack(): void
    {
        $result = new StackDirective()->compile("'scripts'");

        self::assertStringContainsString('implode', $result);
        self::assertStringContainsString('$__stacks', $result);
        self::assertStringContainsString('scripts', $result);
    }

    #[Test]
    public function stackCompileFallsBackToEmptyArray(): void
    {
        $result = new StackDirective()->compile("'missing'");

        // Should use ?? [] to default to empty
        self::assertStringContainsString('?? []', $result);
    }
}
