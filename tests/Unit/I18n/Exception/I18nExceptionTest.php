<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Exception\I18nException;
use RuntimeException;

#[CoversClass(I18nException::class)]
final class I18nExceptionTest extends TestCase
{
    #[Test]
    public function intlRequiredCreatesCorrectMessage(): void
    {
        $exception = I18nException::intlRequired();
        self::assertSame('The ext-intl PHP extension is required in regulated mode but is not loaded', $exception->getMessage());
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function notBootedCreatesCorrectMessage(): void
    {
        $exception = I18nException::notBooted();
        self::assertSame('I18n system has not been booted. Ensure I18nWiring is registered and config/i18n.php exists', $exception->getMessage());
    }

    #[Test]
    public function unsupportedLocaleCreatesCorrectMessage(): void
    {
        $exception = I18nException::unsupportedLocale('zh', ['en', 'fr', 'de']);
        self::assertSame('Locale "zh" is not in the supported locales list: [en, fr, de]', $exception->getMessage());
    }
}
