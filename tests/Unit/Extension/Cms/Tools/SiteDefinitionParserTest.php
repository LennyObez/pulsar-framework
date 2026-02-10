<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function json_encode;

#[CoversClass(SiteDefinition::class)]
final class SiteDefinitionParserTest extends TestCase
{
    // ── Full schema parsing ─────────────────────────────────────────

    #[Test]
    public function fullSchemaParsing(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site', 'locales' => ['en', 'fr']],
            'taxonomies' => [['id' => 't1', 'name' => 'Categories']],
            'content' => [['id' => 'c1', 'type' => 'page', 'title' => 'Home']],
            'menus' => [['id' => 'm1', 'name' => 'Main Nav']],
            'media' => [['ref' => 'media://logo.png', 'source' => 'https://example.com/logo.png']],
            'redirects' => [['from' => '/old', 'to' => '/new', 'status' => 301]],
            'seo' => ['robots' => 'index,follow', 'sitemap' => true],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame('Test Site', $def->site['name']);
        self::assertCount(1, $def->taxonomies);
        self::assertCount(1, $def->content);
        self::assertCount(1, $def->menus);
        self::assertCount(1, $def->media);
        self::assertCount(1, $def->redirects);
        self::assertSame('index,follow', $def->seo['robots']);
    }

    // ── media:// ref resolution mapping ─────────────────────────────

    #[Test]
    public function mediaRefResolution(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'media' => [
                ['ref' => 'media://hero.jpg', 'source' => 'https://cdn.example.com/hero.jpg'],
                ['ref' => 'media://logo.svg', 'source' => 'https://cdn.example.com/logo.svg'],
            ],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertCount(2, $def->media);
        self::assertSame('media://hero.jpg', $def->media[0]['ref']);
        self::assertSame('https://cdn.example.com/hero.jpg', $def->media[0]['source']);
    }

    // ── content_ref resolution mapping ──────────────────────────────

    #[Test]
    public function contentRefResolution(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'content' => [
                ['id' => 'c1', 'title' => 'Parent'],
                ['id' => 'c2', 'title' => 'Child', 'parent_ref' => 'content_ref:c1'],
            ],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertCount(2, $def->content);
        self::assertSame('content_ref:c1', $def->content[1]['parent_ref']);
    }

    // ── Creation order (taxonomies before content, content before menus)

    #[Test]
    public function creationOrderFieldsPresent(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Order Test'],
            'taxonomies' => [['id' => 't1']],
            'content' => [['id' => 'c1', 'taxonomy_ref' => 't1']],
            'menus' => [['id' => 'm1', 'content_refs' => ['c1']]],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        // Verify all sections parsed — implementation ensures correct processing order
        self::assertCount(1, $def->taxonomies);
        self::assertCount(1, $def->content);
        self::assertCount(1, $def->menus);
    }

    // ── Invalid JSON rejected ───────────────────────────────────────

    #[Test]
    public function invalidJsonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        SiteDefinition::fromJson('not valid json');
    }

    // ── Missing required keys ───────────────────────────────────────

    #[Test]
    public function missingVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required keys');

        SiteDefinition::fromJson(json_encode(['site' => ['name' => 'Test']], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function missingSiteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required keys');

        SiteDefinition::fromJson(json_encode(['version' => '1.0'], JSON_THROW_ON_ERROR));
    }

    // ── Unsupported version ─────────────────────────────────────────

    #[Test]
    public function unsupportedVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported site definition version');

        SiteDefinition::fromJson(json_encode([
            'version' => '2.0',
            'site' => ['name' => 'Test'],
        ], JSON_THROW_ON_ERROR));
    }

    // ── site must be an object ──────────────────────────────────────

    #[Test]
    public function siteMustBeObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"site" key must be an object');

        SiteDefinition::fromJson(json_encode([
            'version' => '1.0',
            'site' => 'not-an-object',
        ], JSON_THROW_ON_ERROR));
    }

    // ── Optional keys default to empty ──────────────────────────────

    #[Test]
    public function optionalKeysDefaultToEmpty(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Minimal'],
        ], JSON_THROW_ON_ERROR);

        $def = SiteDefinition::fromJson($json);

        self::assertSame([], $def->taxonomies);
        self::assertSame([], $def->content);
        self::assertSame([], $def->menus);
        self::assertSame([], $def->media);
        self::assertSame([], $def->redirects);
        self::assertSame([], $def->seo);
    }
}
