<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Format\IntlNumberFormatter;
use Pulsar\I18n\TranslatorInterface;

#[CoversClass(IntlNumberFormatter::class)]
#[RequiresPhpExtension('intl')]
final class IntlNumberFormatterTest extends TestCase
{
    #[Test]
    public function formatsIntegerWithLocale(): void
    {
        $translator = $this->createFakeTranslator('en');

        $formatter = new IntlNumberFormatter($translator);

        $result = $formatter->format(1234);

        self::assertSame('1,234', $result);
    }

    #[Test]
    public function formatsFloatWithLocale(): void
    {
        $translator = $this->createFakeTranslator('en');

        $formatter = new IntlNumberFormatter($translator);

        $result = $formatter->format(1234.56);

        self::assertSame('1,234.56', $result);
    }

    #[Test]
    public function usesOverrideLocale(): void
    {
        $translator = $this->createFakeTranslator('en');

        $formatter = new IntlNumberFormatter($translator);

        $result = $formatter->format(1234.56, 'de');

        self::assertStringContainsString('1.234', $result);
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
