<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Compiler\TranslationCompiler;
use Pulsar\I18n\TranslationEntry;

#[CoversClass(TranslationCompiler::class)]
final class TranslationCompilerTest extends TestCase
{
    #[Test]
    public function compilesSimpleDomainToJson(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([
            'greeting' => new TranslationEntry(key: 'greeting', message: 'Hello'),
            'farewell' => new TranslationEntry(key: 'farewell', message: 'Goodbye'),
        ]);

        $compiler = new TranslationCompiler($catalog);
        $json = $compiler->compile('en', 'messages');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Hello', $decoded['greeting']);
        self::assertSame('Goodbye', $decoded['farewell']);
        self::assertCount(2, $decoded);
    }

    #[Test]
    public function compilesEmptyDomainToEmptyObject(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([]);

        $compiler = new TranslationCompiler($catalog);
        $json = $compiler->compile('en', 'messages');

        self::assertSame('{}', $json);
    }

    #[Test]
    public function compilePrettyPrintFormatsOutput(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([
            'key' => new TranslationEntry(key: 'key', message: 'Value'),
        ]);

        $compiler = new TranslationCompiler($catalog);
        $json = $compiler->compile('en', 'messages', prettyPrint: true);

        self::assertStringContainsString("\n", $json);
        self::assertStringContainsString('    ', $json);
    }

    #[Test]
    public function compileAllMergesMultipleDomains(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturnCallback(
            static function (string $locale, string $domain): array {
                return match ($domain) {
                    'messages' => ['greeting' => new TranslationEntry(key: 'greeting', message: 'Hello')],
                    'errors' => ['not_found' => new TranslationEntry(key: 'not_found', message: 'Not found')],
                    default => [],
                };
            },
        );

        $compiler = new TranslationCompiler($catalog);
        $json = $compiler->compileAll('en', ['messages', 'errors']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        // 'messages' domain uses bare keys, 'errors' domain gets prefixed
        self::assertSame('Hello', $decoded['greeting']);
        self::assertSame('Not found', $decoded['errors.not_found']);
    }

    #[Test]
    public function compileAllUsesBarKeysForMessagesDomain(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturnCallback(
            static function (string $locale, string $domain): array {
                return match ($domain) {
                    'messages' => ['save' => new TranslationEntry(key: 'save', message: 'Save')],
                    default => [],
                };
            },
        );

        $compiler = new TranslationCompiler($catalog);
        $json = $compiler->compileAll('en', ['messages']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('save', $decoded);
        self::assertArrayNotHasKey('messages.save', $decoded);
    }

    #[Test]
    public function keysReturnsListOfKeys(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([
            'a' => new TranslationEntry(key: 'a', message: 'A'),
            'b' => new TranslationEntry(key: 'b', message: 'B'),
            'c' => new TranslationEntry(key: 'c', message: 'C'),
        ]);

        $compiler = new TranslationCompiler($catalog);
        $keys = $compiler->keys('en');

        self::assertSame(['a', 'b', 'c'], $keys);
    }

    #[Test]
    public function compilesUnicodeCharactersWithoutEscaping(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('all')->willReturn([
            'greeting' => new TranslationEntry(key: 'greeting', message: 'Bonjour, bienvenue'),
        ]);

        $compiler = new TranslationCompiler($catalog);
        $json = $compiler->compile('fr');

        self::assertStringContainsString('Bonjour', $json);
        self::assertStringNotContainsString('\u', $json);
    }
}
