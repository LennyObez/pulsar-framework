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
    public function pushCompileDelegatesToEnvStartPush(): void
    {
        $result = new PushDirective()->compile("'scripts'");

        self::assertStringContainsString('$__env->startPush(', $result);
        self::assertStringContainsString('scripts', $result);
    }

    #[Test]
    public function pushCompilePassesTheStackName(): void
    {
        $result = new PushDirective()->compile("'styles'");

        self::assertStringContainsString("'styles'", $result);
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
    public function endPushDelegatesToEnvStopPush(): void
    {
        $result = new EndPushDirective()->compile('');

        self::assertStringContainsString('$__env->stopPush()', $result);
    }

    // ── StackDirective ────────────────────────────────────────────────

    #[Test]
    public function stackDirectiveNameIsStack(): void
    {
        self::assertSame('stack', new StackDirective()->name());
    }

    #[Test]
    public function stackCompileEchoesEnvRenderStack(): void
    {
        $result = new StackDirective()->compile("'scripts'");

        self::assertStringContainsString('echo $__env->renderStack(', $result);
        self::assertStringContainsString('scripts', $result);
    }

    #[Test]
    public function stackCompilePassesTheStackName(): void
    {
        $result = new StackDirective()->compile("'missing'");

        self::assertStringContainsString("'missing'", $result);
    }
}
