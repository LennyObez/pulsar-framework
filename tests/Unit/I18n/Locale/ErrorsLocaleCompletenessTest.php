<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function basename;
use function glob;

/**
 * Ensures every locale directory has an errors.php file with
 * all required keys matching the English reference.
 */
final class ErrorsLocaleCompletenessTest extends TestCase
{
    private const string LANG_DIR = __DIR__ . '/../../../../resources/lang';

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        $dirs = glob(self::LANG_DIR . '/*', GLOB_ONLYDIR);
        self::assertNotFalse($dirs);

        foreach ($dirs as $dir) {
            $locale = basename($dir);
            yield $locale => [$locale];
        }
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function errorsFileExistsForLocale(string $locale): void
    {
        $file = self::LANG_DIR . '/' . $locale . '/errors.php';
        self::assertFileExists($file, "Missing errors.php for locale: {$locale}");
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function errorsFileHasAllRequiredKeys(string $locale): void
    {
        $enFile = self::LANG_DIR . '/en/errors.php';
        $localeFile = self::LANG_DIR . '/' . $locale . '/errors.php';

        if (!file_exists($localeFile)) {
            self::markTestSkipped("errors.php missing for {$locale}");
        }

        /** @var array<string, string> $enStrings */
        $enStrings = require $enFile;
        /** @var array<string, string> $localeStrings */
        $localeStrings = require $localeFile;

        self::assertIsArray($localeStrings, "errors.php for {$locale} must return an array");

        $enKeys = array_keys($enStrings);
        $localeKeys = array_keys($localeStrings);

        $missing = array_diff($enKeys, $localeKeys);
        self::assertEmpty(
            $missing,
            "Locale {$locale}/errors.php is missing keys: " . implode(', ', $missing),
        );
    }

    #[Test]
    public function allLocalesHaveUnicodeLanguageNames(): void
    {
        $expected = [
            'lang.fr' => 'Fran',  // starts with Fran (Français has cedilla)
            'lang.es' => 'Espa',  // starts with Espa (Español has tilde)
            'lang.el' => "\u{0395}", // Greek capital Epsilon
            'lang.bg' => "\u{0411}", // Cyrillic Be
        ];

        $enFile = self::LANG_DIR . '/en/core.php';
        /** @var array<string, string> $enStrings */
        $enStrings = require $enFile;

        // Verify French has cedilla (not ASCII Francais)
        self::assertStringContainsString("\u{00E7}", $enStrings['lang.fr'], 'French name must contain cedilla');

        // Verify Spanish has tilde (not ASCII Espanol)
        self::assertStringContainsString("\u{00F1}", $enStrings['lang.es'], 'Spanish name must contain tilde');

        // Verify Greek uses Greek script (not Latin transliteration)
        self::assertStringStartsWith("\u{0395}", $enStrings['lang.el'], 'Greek name must use Greek script');

        // Verify Bulgarian uses Cyrillic (not Latin transliteration)
        self::assertStringStartsWith("\u{0411}", $enStrings['lang.bg'], 'Bulgarian name must use Cyrillic script');
    }
}
