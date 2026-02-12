<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Linter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Linter\LintSeverity;
use Pulsar\I18n\Linter\TranslationLinter;
use Pulsar\I18n\TranslationEntry;

#[CoversClass(TranslationLinter::class)]
final class TranslationLinterTest extends TestCase
{
    #[Test]
    public function detectsHtmlInNonSafeEntry(): void
    {
        $entry = new TranslationEntry(key: 'terms', message: 'Click <a href="#">here</a>', htmlSafe: false);

        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn(['terms' => $entry]);

        $linter = new TranslationLinter($catalog, $this->makeConfig(['en']));
        $result = $linter->lint();

        self::assertGreaterThan(0, $result->count());
        self::assertSame(LintSeverity::Warning, $result->issues[0]['severity']);
        self::assertStringContainsString('HTML', $result->issues[0]['message']);
    }

    #[Test]
    public function allowsHtmlInSafeEntry(): void
    {
        $entry = new TranslationEntry(key: 'terms', message: 'Click <a href="#">here</a>', htmlSafe: true);

        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn(['terms' => $entry]);

        $linter = new TranslationLinter($catalog, $this->makeConfig(['en']));
        $result = $linter->lint();

        // No HTML warnings expected
        $htmlIssues = array_filter(
            $result->issues,
            static fn(array $issue): bool => str_contains($issue['message'], 'HTML'),
        );

        self::assertCount(0, $htmlIssues);
    }

    #[Test]
    public function detectsMaxLengthViolation(): void
    {
        $entry = new TranslationEntry(key: 'long', message: 'This is a long message', maxLength: 5);

        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn(['long' => $entry]);

        $linter = new TranslationLinter($catalog, $this->makeConfig(['en']));
        $result = $linter->lint();

        $lengthIssues = array_filter(
            $result->issues,
            static fn(array $issue): bool => str_contains($issue['message'], 'max_length'),
        );

        self::assertCount(1, $lengthIssues);
    }

    #[Test]
    public function detectsMissingTranslationsAcrossLocales(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturnCallback(
            static function (string $locale): array {
                if ($locale === 'en') {
                    return [
                        'welcome' => new TranslationEntry(key: 'welcome', message: 'Welcome'),
                        'goodbye' => new TranslationEntry(key: 'goodbye', message: 'Goodbye'),
                    ];
                }
                return [
                    'welcome' => new TranslationEntry(key: 'welcome', message: 'Bienvenue'),
                ];
            },
        );

        $linter = new TranslationLinter($catalog, $this->makeConfig(['en', 'fr']));
        $result = $linter->lint();

        $missingIssues = array_filter(
            $result->issues,
            static fn(array $issue): bool => str_contains($issue['message'], 'missing'),
        );

        self::assertCount(1, $missingIssues);
    }

    #[Test]
    public function detectsOrphanedTranslations(): void
    {
        $entry = new TranslationEntry(key: 'orphan', message: 'Orphaned');

        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn(['orphan' => $entry]);

        $linter = new TranslationLinter($catalog, $this->makeConfig(['en']));
        $result = $linter->lint('messages', ['messages' => []]);

        $orphanIssues = array_filter(
            $result->issues,
            static fn(array $issue): bool => str_contains($issue['message'], 'manifest'),
        );

        self::assertCount(1, $orphanIssues);
    }

    #[Test]
    public function noIssuesForCleanCatalog(): void
    {
        $entry = new TranslationEntry(key: 'welcome', message: 'Welcome');

        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn(['welcome' => $entry]);

        $linter = new TranslationLinter($catalog, $this->makeConfig(['en']));
        $result = $linter->lint();

        self::assertSame(0, $result->count());
    }

    /**
     * @param list<string> $locales
     */
    private function makeConfig(array $locales): I18nConfig
    {
        return new I18nConfig(
            defaultLocale: $locales[0],
            supportedLocales: $locales,
            fallbackLocales: [$locales[0]],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        );
    }
}
