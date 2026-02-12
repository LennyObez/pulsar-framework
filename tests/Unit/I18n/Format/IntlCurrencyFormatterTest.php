<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Format\IntlCurrencyFormatter;
use Pulsar\I18n\TranslatorInterface;

#[CoversClass(IntlCurrencyFormatter::class)]
#[RequiresPhpExtension('intl')]
final class IntlCurrencyFormatterTest extends TestCase
{
    #[Test]
    public function formatsCurrencyInUsd(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en_US');

        $formatter = new IntlCurrencyFormatter($translator);

        $result = $formatter->format(1234.56, 'USD');

        self::assertStringContainsString('1,234.56', $result);
    }

    #[Test]
    public function formatsCurrencyInEur(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('de_DE');

        $formatter = new IntlCurrencyFormatter($translator);

        $result = $formatter->format(1234.56, 'EUR');

        self::assertNotEmpty($result);
    }

    #[Test]
    public function usesOverrideLocale(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en_US');

        $formatter = new IntlCurrencyFormatter($translator);

        $result = $formatter->format(1234.56, 'EUR', 'fr_FR');

        self::assertNotEmpty($result);
    }
}
