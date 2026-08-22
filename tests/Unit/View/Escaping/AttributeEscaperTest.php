<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\AttributeEscaper;

#[CoversClass(AttributeEscaper::class)]
final class AttributeEscaperTest extends TestCase
{
    private AttributeEscaper $escaper;

    protected function setUp(): void
    {
        $this->escaper = new AttributeEscaper();
    }

    #[Test]
    public function alphanumericPassesThrough(): void
    {
        self::assertSame('HelloWorld123', $this->escaper->escape('HelloWorld123'));
    }

    #[Test]
    public function specialCharactersAreEncoded(): void
    {
        $result = $this->escaper->escape('<script>alert("xss")</script>');

        self::assertStringNotContainsString('<', $result);
        self::assertStringNotContainsString('"', $result);
        self::assertStringContainsString('&#', $result);
    }

    #[Test]
    public function spacesAreEncoded(): void
    {
        $result = $this->escaper->escape('hello world');

        self::assertSame('hello&#32;world', $result);
    }

    #[Test]
    public function emptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->escaper->escape(''));
    }

    #[Test]
    public function multibyteCjkCharactersAreEncoded(): void
    {
        $result = $this->escaper->escape("\u{4E16}\u{754C}");

        self::assertStringContainsString('&#', $result);
    }
}
