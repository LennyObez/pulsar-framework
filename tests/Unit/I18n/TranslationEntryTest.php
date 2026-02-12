<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\TranslationEntry;

#[CoversClass(TranslationEntry::class)]
final class TranslationEntryTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $entry = new TranslationEntry(key: 'welcome', message: 'Hello!');

        self::assertSame('welcome', $entry->key);
        self::assertSame('Hello!', $entry->message);
        self::assertFalse($entry->htmlSafe);
        self::assertNull($entry->context);
        self::assertNull($entry->maxLength);
    }

    #[Test]
    public function constructsWithAllProperties(): void
    {
        $entry = new TranslationEntry(
            key: 'terms',
            message: 'Agree to <a href="/terms">Terms</a>.',
            htmlSafe: true,
            context: 'Registration page footer',
            maxLength: 200,
        );

        self::assertSame('terms', $entry->key);
        self::assertSame('Agree to <a href="/terms">Terms</a>.', $entry->message);
        self::assertTrue($entry->htmlSafe);
        self::assertSame('Registration page footer', $entry->context);
        self::assertSame(200, $entry->maxLength);
    }
}
