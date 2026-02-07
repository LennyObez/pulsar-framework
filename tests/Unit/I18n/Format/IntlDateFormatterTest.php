<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Format;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Format\IntlDateFormatter;
use Pulsar\I18n\TranslatorInterface;

#[CoversClass(IntlDateFormatter::class)]
#[RequiresPhpExtension('intl')]
final class IntlDateFormatterTest extends TestCase
{
    #[Test]
    public function formatsDate(): void
    {
        $translator = $this->createFakeTranslator('en_US');

        $formatter = new IntlDateFormatter($translator);

        $date = new DateTimeImmutable('2025-01-15 14:30:00');
        $result = $formatter->format($date);

        self::assertNotEmpty($result);
        self::assertStringContainsString('Jan', $result);
    }

    #[Test]
    public function usesOverrideLocale(): void
    {
        $translator = $this->createFakeTranslator('en_US');

        $formatter = new IntlDateFormatter($translator);

        $date = new DateTimeImmutable('2025-01-15 14:30:00');
        $result = $formatter->format($date, 'fr_FR');

        self::assertNotEmpty($result);
        self::assertStringContainsString('janv', $result);
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
