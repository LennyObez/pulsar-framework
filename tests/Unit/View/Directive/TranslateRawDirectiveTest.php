<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\TranslateRawDirective;

#[CoversClass(TranslateRawDirective::class)]
final class TranslateRawDirectiveTest extends TestCase
{
    #[Test]
    public function nameIsTRaw(): void
    {
        $directive = new TranslateRawDirective();

        self::assertSame('tRaw', $directive->name());
    }

    #[Test]
    public function compileProducesUnescapedOutput(): void
    {
        $directive = new TranslateRawDirective();

        $result = $directive->compile("'legal.privacy_link'");

        // Must NOT contain htmlspecialchars (unlike TranslateDirective)
        self::assertStringNotContainsString('htmlspecialchars', $result);
        self::assertStringContainsString('$__translator->translate', $result);
        self::assertStringContainsString("'legal.privacy_link'", $result);
    }

    #[Test]
    public function compileFallsBackToDoubleUnderscoreHelper(): void
    {
        $directive = new TranslateRawDirective();

        $result = $directive->compile("'key'");

        self::assertStringContainsString('__(', $result);
        self::assertStringContainsString('isset($__translator)', $result);
    }

    #[Test]
    public function compileTrimsWhitespace(): void
    {
        $directive = new TranslateRawDirective();

        $result = $directive->compile("  'key', ['name' => \$user]  ");

        self::assertStringContainsString("'key', ['name' => \$user]", $result);
    }

    #[Test]
    public function compileOutputIsPhpEchoStatement(): void
    {
        $directive = new TranslateRawDirective();

        $result = $directive->compile("'test.key'");

        self::assertStringStartsWith('<?php echo ', $result);
        self::assertStringEndsWith('?>', trim($result));
    }
}
