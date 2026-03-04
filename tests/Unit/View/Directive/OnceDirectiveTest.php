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
    public function compileGeneratesOnceBlock(): void
    {
        $result = $this->directive->compile('');

        self::assertStringContainsString('$__once_blocks', $result);
        self::assertStringContainsString('$__once_id', $result);
        self::assertStringContainsString('__FILE__', $result);
        self::assertStringContainsString('__LINE__', $result);
    }

    #[Test]
    public function compileOutputChecksForPreviousRendering(): void
    {
        $result = $this->directive->compile('');

        // Must check if block was already rendered
        self::assertStringContainsString('!isset($__once_blocks[$__once_id])', $result);
    }

    #[Test]
    public function compileOutputMarksBlockAsRendered(): void
    {
        $result = $this->directive->compile('');

        // Must mark block as rendered
        self::assertStringContainsString('$__once_blocks[$__once_id] = true', $result);
    }
}
