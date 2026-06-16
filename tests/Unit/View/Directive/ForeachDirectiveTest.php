<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\ForeachDirective;

#[CoversClass(ForeachDirective::class)]
final class ForeachDirectiveTest extends TestCase
{
    private ForeachDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new ForeachDirective();
    }

    #[Test]
    public function nameReturnsForeach(): void
    {
        self::assertSame('foreach', $this->directive->name());
    }

    #[Test]
    public function compileOutputContainsLoopVariableCreation(): void
    {
        $compiled = $this->directive->compile('$items as $item');

        self::assertStringContainsString('new \Pulsar\View\Engine\LoopVariable(', $compiled);
        self::assertStringContainsString('$loop->step()', $compiled);
        self::assertStringContainsString('$__loopParent = $loop ?? null', $compiled);
    }

    #[Test]
    public function compileOutputContainsForeachStatement(): void
    {
        $compiled = $this->directive->compile('$items as $item');

        // The iterable is materialised into $__loopItems first (generator-safe),
        // so the emitted foreach iterates $__loopItems, not the raw expression.
        self::assertStringContainsString('foreach ($__loopItems as $item):', $compiled);
    }

    #[Test]
    public function compileHandlesKeyValueSyntax(): void
    {
        $compiled = $this->directive->compile('$items as $key => $value');

        self::assertStringContainsString('foreach ($__loopItems as $key => $value):', $compiled);
        self::assertStringContainsString('$__loopItems = $items', $compiled);
    }

    #[Test]
    public function compileHandlesComplexIterable(): void
    {
        $compiled = $this->directive->compile('$user->posts() as $post');

        self::assertStringContainsString('$__loopItems = $user->posts()', $compiled);
    }

    #[Test]
    public function compileTracksNestingDepth(): void
    {
        $compiled = $this->directive->compile('$items as $item');

        self::assertStringContainsString('$__loopDepth', $compiled);
        self::assertStringContainsString('->depth + 1', $compiled);
    }

    #[Test]
    #[DataProvider('expressionProvider')]
    public function compileExtractsIterableCorrectly(string $expression, string $expectedIterable): void
    {
        $compiled = $this->directive->compile($expression);

        self::assertStringContainsString('$__loopItems = ' . $expectedIterable, $compiled);
    }

    /** @return array<string, array{string, string}> */
    public static function expressionProvider(): array
    {
        return [
            'simple variable' => ['$items as $item', '$items'],
            'method call' => ['$user->getPosts() as $post', '$user->getPosts()'],
            'with key' => ['$data as $k => $v', '$data'],
            'null coalesce' => ['($items ?? []) as $item', '($items ?? [])'],
        ];
    }
}
