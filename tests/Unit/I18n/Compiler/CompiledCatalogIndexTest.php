<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Compiler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Compiler\CompiledCatalogIndex;

final class CompiledCatalogIndexTest extends TestCase
{
    #[Test]
    public function constructs_with_all_fields(): void
    {
        $index = new CompiledCatalogIndex(
            locales: ['en', 'fr', 'de'],
            index: [
                'en' => ['messages' => ['greeting', 'farewell']],
                'fr' => ['messages' => ['greeting']],
            ],
            fileHashes: ['en/messages.php' => 'abc123'],
            totalHash: 'total-hash-xyz',
        );

        self::assertSame(['en', 'fr', 'de'], $index->locales);
        self::assertCount(2, $index->index);
        self::assertSame(['en/messages.php' => 'abc123'], $index->fileHashes);
        self::assertSame('total-hash-xyz', $index->totalHash);
    }

    #[Test]
    public function from_array_parses_complete_data(): void
    {
        $data = [
            'locales' => ['en', 'es'],
            'index' => [
                'en' => ['validation' => ['required', 'email']],
            ],
            'file_hashes' => ['en/validation.php' => 'hash-1'],
            'total_hash' => 'combined-hash',
        ];

        $index = CompiledCatalogIndex::fromArray($data);

        self::assertSame(['en', 'es'], $index->locales);
        self::assertSame(['required', 'email'], $index->index['en']['validation']);
        self::assertSame('hash-1', $index->fileHashes['en/validation.php']);
        self::assertSame('combined-hash', $index->totalHash);
    }

    #[Test]
    public function from_array_defaults_for_missing_keys(): void
    {
        $index = CompiledCatalogIndex::fromArray([]);

        self::assertSame([], $index->locales);
        self::assertSame([], $index->index);
        self::assertSame([], $index->fileHashes);
        self::assertSame('', $index->totalHash);
    }

    #[Test]
    public function from_array_ignores_non_array_locales(): void
    {
        $index = CompiledCatalogIndex::fromArray(['locales' => 'not-array']);

        self::assertSame([], $index->locales);
    }

    #[Test]
    public function from_array_ignores_non_string_total_hash(): void
    {
        $index = CompiledCatalogIndex::fromArray(['total_hash' => 42]);

        self::assertSame('', $index->totalHash);
    }

    #[Test]
    public function to_array_round_trip(): void
    {
        $original = new CompiledCatalogIndex(
            locales: ['en', 'fr'],
            index: ['en' => ['messages' => ['hello']]],
            fileHashes: ['en/messages.php' => 'sha256-abc'],
            totalHash: 'overall-hash',
        );

        $restored = CompiledCatalogIndex::fromArray($original->toArray());

        self::assertSame($original->locales, $restored->locales);
        self::assertSame($original->index, $restored->index);
        self::assertSame($original->fileHashes, $restored->fileHashes);
        self::assertSame($original->totalHash, $restored->totalHash);
    }

    #[Test]
    public function to_array_structure(): void
    {
        $index = new CompiledCatalogIndex(
            locales: ['ja'],
            index: [],
            fileHashes: [],
            totalHash: 'hash',
        );

        $array = $index->toArray();

        self::assertArrayHasKey('locales', $array);
        self::assertArrayHasKey('index', $array);
        self::assertArrayHasKey('file_hashes', $array);
        self::assertArrayHasKey('total_hash', $array);
        self::assertSame(['ja'], $array['locales']);
    }
}
