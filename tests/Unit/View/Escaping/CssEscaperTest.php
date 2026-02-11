<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Escaping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Escaping\CssEscaper;

#[CoversClass(CssEscaper::class)]
final class CssEscaperTest extends TestCase
{
    private CssEscaper $escaper;

    protected function setUp(): void
    {
        $this->escaper = new CssEscaper();
    }

    #[Test]
    public function alphanumericPassesThrough(): void
    {
        self::assertSame('Arial10px', $this->escaper->escape('Arial10px'));
    }

    #[Test]
    public function specialCharactersGetCssHexEscaped(): void
    {
        $result = $this->escaper->escape('expression(alert(1))');

        self::assertStringNotContainsString('(', $result);
        self::assertStringContainsString('\\', $result);
    }

    #[Test]
    public function semicolonIsEscaped(): void
    {
        $result = $this->escaper->escape('red; background: url(evil)');

        self::assertStringNotContainsString(';', $result);
    }

    #[Test]
    public function emptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->escaper->escape(''));
    }
}
