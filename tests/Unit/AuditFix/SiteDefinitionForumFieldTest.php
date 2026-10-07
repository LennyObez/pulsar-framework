<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\SiteDefinition;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies that SiteDefinition supports the optional forum field
 * for cross-extension import.
 */
#[CoversClass(SiteDefinition::class)]
final class SiteDefinitionForumFieldTest extends TestCase
{
    #[Test]
    public function forumFieldIsParsedFromJson(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'forum' => [
                'categories' => [
                    ['name' => 'General', 'slug' => 'general'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $definition = SiteDefinition::fromJson($json);

        self::assertNotNull($definition->forum);
        self::assertArrayHasKey('categories', $definition->forum);
    }

    #[Test]
    public function forumFieldIsNullWhenAbsent(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
        ], JSON_THROW_ON_ERROR);

        $definition = SiteDefinition::fromJson($json);

        self::assertNull($definition->forum);
    }

    #[Test]
    public function forumFieldIsNullWhenNotArray(): void
    {
        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'forum' => 'invalid',
        ], JSON_THROW_ON_ERROR);

        $definition = SiteDefinition::fromJson($json);

        self::assertNull($definition->forum);
    }

    #[Test]
    public function constructorAcceptsForumParameter(): void
    {
        $forumData = ['threads' => [['title' => 'Welcome']]];

        $definition = new SiteDefinition(
            site: ['name' => 'Test'],
            taxonomies: [],
            content: [],
            menus: [],
            media: [],
            redirects: [],
            seo: [],
            forum: $forumData,
        );

        self::assertSame($forumData, $definition->forum);
    }
}
