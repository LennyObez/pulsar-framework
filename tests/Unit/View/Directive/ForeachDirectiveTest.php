<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\ForeachDirective;
use Pulsar\View\Engine\LoopVariable;

use function ob_end_clean;
use function ob_start;

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

    #[Test]
    public function keyedGeneratorPreservesRealKeysAndCount(): void
    {
        // A non-Countable Traversable iterated with `as $k => $v` must yield
        // the real keys (not the 0,1,2,... produced by re-indexing) and a correct
        // $loop->count.
        $gen = (static function (): Generator {
            yield 'first' => 'a';
            yield 'second' => 'b';
            yield 'third' => 'c';
        })();

        $open = $this->directive->compile('$gen as $k => $v');
        /** @var list<string> $out */
        $out = [];
        /** @var LoopVariable|null $loop */
        $loop = null;

        // eval() runs the directive's OWN generated PHP (a trusted compiler
        // artifact built from a fixed expression, not user input) so the loop's
        // runtime behaviour can be asserted — the canonical way to test compiled
        // template output.
        ob_start();
        eval('?>' . $open . '<?php $out[] = $k . "=" . $v; ?><?php endforeach; ?>');
        ob_end_clean();

        self::assertInstanceOf(LoopVariable::class, $loop);
        self::assertSame(['first=a', 'second=b', 'third=c'], $out);
        self::assertSame(3, $loop->count);
    }

    #[Test]
    public function keyedGeneratorWithDuplicateKeysFollowsArraySemantics(): void
    {
        // Materialising a keyed Traversable to size $loop uses array
        // semantics, so a duplicate key keeps the last value (documented behaviour).
        $gen = (static function (): Generator {
            yield 'dup' => 1;
            yield 'dup' => 2;
        })();

        $open = $this->directive->compile('$gen as $k => $v');
        /** @var list<string> $out */
        $out = [];
        /** @var LoopVariable|null $loop */
        $loop = null;

        // eval() runs the directive's OWN generated PHP (a trusted compiler
        // artifact built from a fixed expression, not user input) so the loop's
        // runtime behaviour can be asserted — the canonical way to test compiled
        // template output.
        ob_start();
        eval('?>' . $open . '<?php $out[] = $k . "=" . $v; ?><?php endforeach; ?>');
        ob_end_clean();

        self::assertInstanceOf(LoopVariable::class, $loop);
        self::assertSame(['dup=2'], $out);
        self::assertSame(1, $loop->count);
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
