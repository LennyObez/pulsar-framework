<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\SyntaxHighlighter;

#[CoversClass(SyntaxHighlighter::class)]
final class SyntaxHighlighterTest extends TestCase
{
    private SyntaxHighlighter $highlighter;

    protected function setUp(): void
    {
        $this->highlighter = new SyntaxHighlighter(colorsEnabled: true);
    }

    #[Test]
    public function highlightKeywordsWrapsInAnsiBlue(): void
    {
        $result = $this->highlighter->highlight('return $value');

        // The keyword "return" should be wrapped in ANSI codes
        self::assertStringContainsString("\033[1;34mreturn\033[0m", $result);
    }

    #[Test]
    public function highlightVariablesWrapsInAnsiYellow(): void
    {
        $result = $this->highlighter->highlight('$myVariable');

        self::assertStringContainsString("\033[0;33m\$myVariable\033[0m", $result);
    }

    #[Test]
    public function highlightDoubleQuotedStringsWrapsInGreen(): void
    {
        $result = $this->highlighter->highlight('"hello world"');

        self::assertStringContainsString("\033[0;32m\"hello world\"\033[0m", $result);
    }

    #[Test]
    public function highlightSingleQuotedStringsWrapsInGreen(): void
    {
        $result = $this->highlighter->highlight("'hello world'");

        self::assertStringContainsString("\033[0;32m'hello world'\033[0m", $result);
    }

    #[Test]
    public function highlightIntegerNumbersWrapsInCyan(): void
    {
        $result = $this->highlighter->highlight('42');

        self::assertStringContainsString("\033[0;36m42\033[0m", $result);
    }

    #[Test]
    public function highlightFloatNumbers(): void
    {
        $result = $this->highlighter->highlight('3.14');

        self::assertStringContainsString("\033[0;36m3.14\033[0m", $result);
    }

    #[Test]
    public function highlightHexNumbers(): void
    {
        $result = $this->highlighter->highlight('0xFF');

        self::assertStringContainsString("\033[0;36m0xFF\033[0m", $result);
    }

    #[Test]
    public function highlightSingleLineComments(): void
    {
        $result = $this->highlighter->highlight('// this is a comment');

        self::assertStringContainsString("\033[0;90m// this is a comment\033[0m", $result);
    }

    #[Test]
    public function highlightMultiLineComments(): void
    {
        $result = $this->highlighter->highlight('/* comment */');

        self::assertStringContainsString("\033[0;90m/* comment */\033[0m", $result);
    }

    #[Test]
    public function highlightFunctionCallsWrapsInBrightCyan(): void
    {
        $result = $this->highlighter->highlight('array_map(');

        self::assertStringContainsString("\033[0;96marray_map\033[0m", $result);
    }

    #[Test]
    public function disabledHighlighterReturnsRawCode(): void
    {
        $disabled = new SyntaxHighlighter(colorsEnabled: false);
        $code = 'return $value + 42';

        $result = $disabled->highlight($code);

        self::assertSame($code, $result);
    }

    #[Test]
    public function stripAnsiRemovesAllEscapeSequences(): void
    {
        $colored = "\033[1;34mreturn\033[0m \033[0;33m\$x\033[0m";
        $stripped = SyntaxHighlighter::stripAnsi($colored);

        self::assertSame('return $x', $stripped);
    }

    #[Test]
    public function stripAnsiOnPlainTextIsNoop(): void
    {
        $plain = 'no colors here';

        self::assertSame($plain, SyntaxHighlighter::stripAnsi($plain));
    }

    #[Test]
    public function highlightEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->highlighter->highlight(''));
    }

    #[Test]
    public function highlightHandlesEscapedQuotesInStrings(): void
    {
        $result = $this->highlighter->highlight('"escaped \\" quote"');

        // The string should be treated as a single unit
        self::assertStringContainsString("\033[0;32m", $result);
    }

    #[Test]
    #[DataProvider('keywordProvider')]
    public function highlightRecognizesPhpKeywords(string $keyword): void
    {
        $result = $this->highlighter->highlight($keyword . ' ');

        // Keyword should have ANSI bold blue wrapping
        self::assertStringContainsString("\033[1;34m{$keyword}\033[0m", $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keywordProvider(): iterable
    {
        yield 'class' => ['class'];
        yield 'function' => ['function'];
        yield 'return' => ['return'];
        yield 'if' => ['if'];
        yield 'foreach' => ['foreach'];
        yield 'match' => ['match'];
        yield 'enum' => ['enum'];
        yield 'readonly' => ['readonly'];
        yield 'throw' => ['throw'];
    }

    #[Test]
    public function highlightDynamicVariables(): void
    {
        $result = $this->highlighter->highlight('$$dynamic');

        self::assertStringContainsString("\033[0;33m\$\$dynamic\033[0m", $result);
    }

    #[Test]
    public function highlightBinaryLiterals(): void
    {
        $result = $this->highlighter->highlight('0b1010');

        self::assertStringContainsString("\033[0;36m0b1010\033[0m", $result);
    }

    #[Test]
    public function highlightOctalLiterals(): void
    {
        $result = $this->highlighter->highlight('0o777');

        self::assertStringContainsString("\033[0;36m0o777\033[0m", $result);
    }

    #[Test]
    public function highlightScientificNotation(): void
    {
        $result = $this->highlighter->highlight('1.5e10');

        self::assertStringContainsString("\033[0;36m1.5e10\033[0m", $result);
    }
}
