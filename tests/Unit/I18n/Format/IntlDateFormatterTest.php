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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en_US');

        $formatter = new IntlDateFormatter($translator);

        $date = new DateTimeImmutable('2025-01-15 14:30:00');
        $result = $formatter->format($date);

        self::assertNotEmpty($result);
        self::assertStringContainsString('Jan', $result);
    }

    #[Test]
    public function usesOverrideLocale(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en_US');

        $formatter = new IntlDateFormatter($translator);

        $date = new DateTimeImmutable('2025-01-15 14:30:00');
        $result = $formatter->format($date, 'fr_FR');

        self::assertNotEmpty($result);
        self::assertStringContainsString('janv', $result);
    }
}
