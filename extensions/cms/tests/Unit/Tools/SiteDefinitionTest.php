<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SiteDefinition::class)]
final class SiteDefinitionTest extends TestCase
{
    #[Test]
    public function parsesValidSiteDefinition(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site', 'url' => 'https://example.com'],
            'content' => [
                ['slug' => 'about', 'title' => 'About Us', 'import_id' => 'page:about'],
            ],
            'taxonomies' => [
                ['slug' => 'tags', 'name' => 'Tags', 'import_id' => 'tax:tags'],
            ],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame('Test Site', $def->site['name']);
        self::assertCount(1, $def->content);
        self::assertCount(1, $def->taxonomies);
        self::assertSame('page:about', $def->content[0]['import_id']);
    }

    #[Test]
    public function preservesImportIdOnAllEntities(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'content' => [
                ['slug' => 'p1', 'import_id' => 'page:p1'],
            ],
            'taxonomies' => [
                ['slug' => 'cats', 'import_id' => 'tax:cats'],
            ],
            'menus' => [
                ['location' => 'primary', 'import_id' => 'menu:primary'],
            ],
            'media' => [
                ['ref' => 'logo.png', 'import_id' => 'media:logo'],
            ],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame('page:p1', $def->content[0]['import_id']);
        self::assertSame('tax:cats', $def->taxonomies[0]['import_id']);
        self::assertSame('menu:primary', $def->menus[0]['import_id']);
        self::assertSame('media:logo', $def->media[0]['import_id']);
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid JSON');

        SiteDefinition::fromJson('{not valid json');
    }

    #[Test]
    public function rejectsMissingVersionKey(): void
    {
        $json = json_encode([
            'site' => ['name' => 'Test'],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Missing required keys');

        SiteDefinition::fromJson($json);
    }

    #[Test]
    public function rejectsUnsupportedVersion(): void
    {
        $json = json_encode([
            'version' => '2.0',
            'site' => ['name' => 'Test'],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported site definition version');

        SiteDefinition::fromJson($json);
    }

    #[Test]
    public function defaultsToEmptyArraysForOptionalSections(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Minimal'],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame([], $def->content);
        self::assertSame([], $def->taxonomies);
        self::assertSame([], $def->menus);
        self::assertSame([], $def->media);
        self::assertSame([], $def->redirects);
        self::assertSame([], $def->seo);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null literal' => ['null'];
        yield 'array instead of object' => ['[1,2,3]'];
    }

    #[Test]
    #[DataProvider('invalidJsonProvider')]
    public function rejectsNonObjectJson(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        SiteDefinition::fromJson($json);
    }
}
