<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Documentation\DocPage;

#[CoversClass(DocPage::class)]
final class DocPageTest extends TestCase
{
    public function testUrlWithoutSection(): void
    {
        $page = new DocPage(
            slug: 'routing',
            title: 'Routing',
            content: '# Routing',
            version: '1.0',
        );

        self::assertSame('/docs/1.0/routing', $page->url());
    }

    public function testUrlWithSection(): void
    {
        $page = new DocPage(
            slug: 'queries',
            title: 'Queries',
            content: '# Queries',
            version: '1.0',
            section: 'database',
        );

        self::assertSame('/docs/1.0/database/queries', $page->url());
    }

    public function testProperties(): void
    {
        $page = new DocPage(
            slug: 'install',
            title: 'Installation',
            content: '# Install Pulsar',
            version: '1.0',
            section: 'getting-started',
            order: 1,
            description: 'How to install Pulsar',
        );

        self::assertSame('install', $page->slug);
        self::assertSame('Installation', $page->title);
        self::assertSame('# Install Pulsar', $page->content);
        self::assertSame('1.0', $page->version);
        self::assertSame('getting-started', $page->section);
        self::assertSame(1, $page->order);
        self::assertSame('How to install Pulsar', $page->description);
    }
}
