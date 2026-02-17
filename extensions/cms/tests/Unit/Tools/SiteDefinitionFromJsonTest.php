<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

#[CoversClass(SiteDefinition::class)]
final class SiteDefinitionFromJsonTest extends TestCase
{
    #[Test]
    public function fromJsonParsesValidDefinition(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site'],
            'taxonomies' => [['slug' => 'category']],
            'content' => [['title' => 'Home']],
            'menus' => [],
            'media' => [],
            'redirects' => [],
            'seo' => ['robots' => 'index'],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame('Test Site', $def->site['name']);
        self::assertCount(1, $def->taxonomies);
        self::assertCount(1, $def->content);
        self::assertSame([], $def->menus);
        self::assertSame('index', $def->seo['robots']);
        self::assertNull($def->forum);
    }

    #[Test]
    public function fromJsonIncludesOptionalForumSection(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'forum' => ['categories' => [['name' => 'General']]],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertNotNull($def->forum);
        self::assertArrayHasKey('categories', $def->forum);
    }

    #[Test]
    public function fromJsonThrowsForInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        SiteDefinition::fromJson('{invalid');
    }

    #[Test]
    public function fromJsonThrowsForMissingRequiredKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required keys');

        SiteDefinition::fromJson(json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function fromJsonThrowsForUnsupportedVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported site definition version');

        SiteDefinition::fromJson(json_encode([
            'version' => '2.0',
            'site' => ['name' => 'Test'],
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function fromJsonThrowsForNonObjectSite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"site" key must be an object');

        SiteDefinition::fromJson(json_encode([
            'version' => '1.0',
            'site' => 'not-an-object',
        ], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function constructSetsAllProperties(): void
    {
        $def = new SiteDefinition(
            site: ['name' => 'My Site'],
            taxonomies: [['slug' => 'tag']],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
            forum: ['enabled' => true],
        );

        self::assertSame('My Site', $def->site['name']);
        self::assertCount(1, $def->taxonomies);
        self::assertNotNull($def->forum);
    }
}
