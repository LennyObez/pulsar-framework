<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\I18nDirective;

#[CoversClass(I18nDirective::class)]
final class I18nDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsI18n(): void
    {
        self::assertSame('i18n', new I18nDirective()->name());
    }

    #[Test]
    public function compileProducesEscapedTranslationCall(): void
    {
        $output = new I18nDirective()->compile("'messages.welcome'");

        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('__(', $output);
        self::assertStringContainsString("'messages.welcome'", $output);
    }
}
