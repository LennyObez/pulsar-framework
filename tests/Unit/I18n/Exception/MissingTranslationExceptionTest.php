<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Exception\MissingTranslationException;

#[CoversClass(MissingTranslationException::class)]
final class MissingTranslationExceptionTest extends TestCase
{
    #[Test]
    public function forKeyCreatesCorrectMessage(): void
    {
        $exception = MissingTranslationException::forKey('welcome', 'fr', 'messages');
        self::assertSame('Missing translation for key "welcome" in locale "fr", domain "messages"', $exception->getMessage());
        self::assertInstanceOf(I18nException::class, $exception);
    }
}
