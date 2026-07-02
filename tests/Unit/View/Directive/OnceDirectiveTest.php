<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\OnceDirective;

final class OnceDirectiveTest extends TestCase
{
    private OnceDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new OnceDirective();
    }

    #[Test]
    public function nameReturnsOnce(): void
    {
        self::assertSame('once', $this->directive->name());
    }

    #[Test]
    public function compileDelegatesToEnvRenderOnce(): void
    {
        $result = $this->directive->compile('');

        // The once-registry now lives on the shared $__env so it survives
        // @include boundaries (once per request), not on a template-local var.
        self::assertStringContainsString('$__env->renderOnce(', $result);
    }

    #[Test]
    public function compileUsesFileAndLineIdentity(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('__FILE__', $result);
        self::assertStringContainsString('__LINE__', $result);
    }

    #[Test]
    public function compileOpensAnIfGuardClosedByEndonce(): void
    {
        $result = $this->directive->compile('');

        // Alternative-syntax `if (...):` so the @endonce directive's `endif;`
        // closes it.
        self::assertStringContainsString('if (', $result);
        self::assertStringContainsString('):', $result);
    }
}
