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
        $translator = $this->createFakeTranslator('en_US');

        $formatter = new IntlCurrencyFormatter($translator);

        $result = $formatter->format(1234.56, 'USD');

        self::assertStringContainsString('1,234.56', $result);
    }

    #[Test]
    public function formatsCurrencyInEur(): void
    {
        $translator = $this->createFakeTranslator('de_DE');

        $formatter = new IntlCurrencyFormatter($translator);

        $result = $formatter->format(1234.56, 'EUR');

        self::assertNotEmpty($result);
    }

    #[Test]
    public function usesOverrideLocale(): void
    {
        $translator = $this->createFakeTranslator('en_US');

        $formatter = new IntlCurrencyFormatter($translator);

        $result = $formatter->format(1234.56, 'EUR', 'fr_FR');

        self::assertNotEmpty($result);
    }

    /**
     * PHPUnit stubs of PHP 8.5 interface property hooks are no-ops,
     * so we use an anonymous class with a real property.
     */
    private function createFakeTranslator(string $locale): TranslatorInterface
    {
        $translator = new class implements TranslatorInterface {
            public string $locale = '';

            public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
            {
                return $key;
            }

            public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
            {
                return false;
            }
        };
        $translator->locale = $locale;

        return $translator;
    }
}
