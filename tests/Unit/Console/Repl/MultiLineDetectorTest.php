<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\MultiLineDetector;
use Pulsar\Console\Repl\MultiLineState;

#[CoversClass(MultiLineDetector::class)]
#[CoversClass(MultiLineState::class)]
final class MultiLineDetectorTest extends TestCase
{
    private MultiLineDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MultiLineDetector();
    }

    #[Test]
    public function simpleExpressionIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete('$x = 1;'));
    }

    #[Test]
    public function emptyInputIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete(''));
    }

    #[Test]
    public function unclosedBraceIsIncomplete(): void
    {
        self::assertFalse($this->detector->isComplete('if ($x) {'));
    }

    #[Test]
    public function closedBraceIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete('if ($x) { return 1; }'));
    }

    #[Test]
    public function unclosedParenthesisIsIncomplete(): void
    {
        self::assertFalse($this->detector->isComplete('array_map(fn($x) =>'));
    }

    #[Test]
    public function closedParenthesisIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete('array_map(fn($x) => $x)'));
    }

    #[Test]
    public function unclosedBracketIsIncomplete(): void
    {
        self::assertFalse($this->detector->isComplete('$arr = [1, 2,'));
    }

    #[Test]
    public function closedBracketIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete('$arr = [1, 2, 3]'));
    }

    #[Test]
    public function unclosedDoubleQuoteIsIncomplete(): void
    {
        self::assertFalse($this->detector->isComplete('"hello'));
    }

    #[Test]
    public function closedDoubleQuoteIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete('"hello world"'));
    }

    #[Test]
    public function unclosedSingleQuoteIsIncomplete(): void
    {
        self::assertFalse($this->detector->isComplete("'hello"));
    }

    #[Test]
    public function closedSingleQuoteIsComplete(): void
    {
        self::assertTrue($this->detector->isComplete("'hello world'"));
    }

    #[Test]
    public function escapedQuoteDoesNotCloseString(): void
    {
        self::assertFalse($this->detector->isComplete('"hello \\"'));
    }

    #[Test]
    public function escapedSingleQuoteDoesNotCloseString(): void
    {
        self::assertFalse($this->detector->isComplete("'hello \\'"));
    }

    #[Test]
    public function nestedBracesTrackCorrectly(): void
    {
        $input = "class Foo {\n  public function bar() {\n";
        self::assertFalse($this->detector->isComplete($input));
    }

    #[Test]
    public function nestedBracesCompleteWhenClosed(): void
    {
        $input = "class Foo {\n  public function bar() {\n    return 1;\n  }\n}";
        self::assertTrue($this->detector->isComplete($input));
    }

    #[Test]
    public function singleLineCommentDoesNotAffectBraces(): void
    {
        $input = "// { this is a comment\n\$x = 1;";
        self::assertTrue($this->detector->isComplete($input));
    }

    #[Test]
    public function multiLineCommentDoesNotAffectParens(): void
    {
        $input = '/* ( not a real paren */ $x = 1;';
        self::assertTrue($this->detector->isComplete($input));
    }

    #[Test]
    public function bracesInsideStringsAreIgnored(): void
    {
        self::assertTrue($this->detector->isComplete('$x = "{ not a brace }"'));
    }

    #[Test]
    public function trailingBackslashIsIncomplete(): void
    {
        self::assertFalse($this->detector->isComplete('$x = 1 + \\'));
    }

    #[Test]
    public function analyzeReturnsBraceCount(): void
    {
        $state = $this->detector->analyze('{ { }');

        self::assertSame(1, $state->braces);
        self::assertFalse($state->isComplete());
    }

    #[Test]
    public function analyzeReturnsParenCount(): void
    {
        $state = $this->detector->analyze('foo((bar(');

        self::assertSame(3, $state->parentheses);
    }

    #[Test]
    public function analyzeReturnsBracketCount(): void
    {
        $state = $this->detector->analyze('[1, [2,');

        self::assertSame(2, $state->brackets);
    }

    #[Test]
    public function analyzeReportsOpenSingleQuote(): void
    {
        $state = $this->detector->analyze("'unclosed");

        self::assertTrue($state->inSingleQuote);
        self::assertFalse($state->isComplete());
    }

    #[Test]
    public function analyzeReportsOpenDoubleQuote(): void
    {
        $state = $this->detector->analyze('"unclosed');

        self::assertTrue($state->inDoubleQuote);
        self::assertFalse($state->isComplete());
    }

    #[Test]
    #[DataProvider('reasonProvider')]
    public function reasonDescribesIncompleteState(string $input, string $expectedFragment): void
    {
        $state = $this->detector->analyze($input);

        self::assertStringContainsString($expectedFragment, $state->reason());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reasonProvider(): iterable
    {
        yield 'unclosed brace' => ['{', 'brace'];
        yield 'unclosed paren' => ['(', 'parenthesis'];
        yield 'unclosed bracket' => ['[', 'bracket'];
        yield 'unclosed single quote' => ["'x", 'single-quoted'];
        yield 'unclosed double quote' => ['"x', 'double-quoted'];
        yield 'trailing backslash' => ['x \\', 'backslash'];
    }

    #[Test]
    public function completeInputReasonSaysComplete(): void
    {
        $state = $this->detector->analyze('$x = 1;');

        self::assertSame('Complete', $state->reason());
    }

    #[Test]
    public function hashCommentDoesNotAffectState(): void
    {
        $input = "# comment with { brace\n\$x = 1;";
        self::assertTrue($this->detector->isComplete($input));
    }

    #[Test]
    public function complexMultiLineExpression(): void
    {
        $input = <<<'PHP'
            $result = array_map(
                fn($item) => [
                    'name' => $item->getName(),
                    'value' => $item->getValue(),
                ],
                $items,
            )
            PHP;

        self::assertTrue($this->detector->isComplete($input));
    }

    #[Test]
    public function partialMultiLineExpression(): void
    {
        $input = <<<'PHP'
            $result = array_map(
                fn($item) => [
                    'name' => $item->getName(),
            PHP;

        self::assertFalse($this->detector->isComplete($input));
    }
}
