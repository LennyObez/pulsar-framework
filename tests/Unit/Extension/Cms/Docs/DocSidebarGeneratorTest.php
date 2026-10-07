<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Extension\Cms\Docs\DocSidebarGenerator;

#[CoversClass(DocSidebarGenerator::class)]
final class DocSidebarGeneratorTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
    }

    #[Test]
    public function generateGroupsItemsBySection(): void
    {
        $result = Result::fromArrays([
            ['title' => 'Installation', 'slug' => 'install', 'path' => '/docs/install', 'section' => 'Getting Started', 'sort_order' => 1],
            ['title' => 'Configuration', 'slug' => 'config', 'path' => '/docs/config', 'section' => 'Getting Started', 'sort_order' => 2],
            ['title' => 'Models', 'slug' => 'models', 'path' => '/docs/models', 'section' => 'Core', 'sort_order' => 1],
        ]);

        $this->connection->method('query')->willReturn($result);

        $generator = new DocSidebarGenerator($this->connection);
        $sidebar = $generator->generate('en');

        self::assertCount(2, $sidebar);

        self::assertSame('Getting Started', $sidebar[0]['section']);
        self::assertCount(2, $sidebar[0]['items']);
        self::assertSame('Installation', $sidebar[0]['items'][0]['title']);
        self::assertSame('install', $sidebar[0]['items'][0]['slug']);
        self::assertSame('/docs/install', $sidebar[0]['items'][0]['path']);
        self::assertSame(1, $sidebar[0]['items'][0]['order']);
        self::assertSame('Configuration', $sidebar[0]['items'][1]['title']);

        self::assertSame('Core', $sidebar[1]['section']);
        self::assertCount(1, $sidebar[1]['items']);
        self::assertSame('Models', $sidebar[1]['items'][0]['title']);
    }

    #[Test]
    public function generateReturnsEmptyForNoContent(): void
    {
        $result = Result::fromArrays([]);

        $this->connection->method('query')->willReturn($result);

        $generator = new DocSidebarGenerator($this->connection);
        $sidebar = $generator->generate('en');

        self::assertSame([], $sidebar);
    }

    #[Test]
    public function generatePreservesItemOrderWithinSection(): void
    {
        $result = Result::fromArrays([
            ['title' => 'First', 'slug' => 'first', 'path' => '/docs/first', 'section' => 'Guide', 'sort_order' => 1],
            ['title' => 'Second', 'slug' => 'second', 'path' => '/docs/second', 'section' => 'Guide', 'sort_order' => 2],
            ['title' => 'Third', 'slug' => 'third', 'path' => '/docs/third', 'section' => 'Guide', 'sort_order' => 3],
        ]);

        $this->connection->method('query')->willReturn($result);

        $generator = new DocSidebarGenerator($this->connection);
        $sidebar = $generator->generate('en');

        self::assertCount(1, $sidebar);
        self::assertSame('First', $sidebar[0]['items'][0]['title']);
        self::assertSame('Second', $sidebar[0]['items'][1]['title']);
        self::assertSame('Third', $sidebar[0]['items'][2]['title']);
    }

    #[Test]
    public function generateUsesGeneralForNullSection(): void
    {
        $result = Result::fromArrays([
            ['title' => 'Uncategorized', 'slug' => 'uncat', 'path' => '/docs/uncat', 'section' => null, 'sort_order' => 0],
        ]);

        $this->connection->method('query')->willReturn($result);

        $generator = new DocSidebarGenerator($this->connection);
        $sidebar = $generator->generate('en');

        self::assertCount(1, $sidebar);
        self::assertSame('General', $sidebar[0]['section']);
    }

    #[Test]
    public function generateWithVersionFilterUsesVersionQuery(): void
    {
        $result = Result::fromArrays([
            ['title' => 'V2 Page', 'slug' => 'v2-page', 'path' => '/docs/v2-page', 'section' => 'Docs', 'sort_order' => 1],
        ]);

        $this->connection->method('query')->willReturn($result);

        $generator = new DocSidebarGenerator($this->connection);
        $sidebar = $generator->generate('en', version: '2.0');

        self::assertCount(1, $sidebar);
        self::assertSame('V2 Page', $sidebar[0]['items'][0]['title']);
    }
}
