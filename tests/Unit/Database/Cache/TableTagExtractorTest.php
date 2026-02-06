<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\TableTagExtractor;

#[CoversClass(TableTagExtractor::class)]
final class TableTagExtractorTest extends TestCase
{
    private TableTagExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TableTagExtractor();
    }

    #[Test]
    public function extractsTableFromSelect(): void
    {
        $tags = $this->extractor->extractTags('SELECT * FROM users WHERE id = 1');

        self::assertSame(['users'], $tags);
    }

    #[Test]
    public function extractsTableFromJoin(): void
    {
        $tags = $this->extractor->extractTags('SELECT * FROM users JOIN orders ON users.id = orders.user_id');

        self::assertContains('users', $tags);
        self::assertContains('orders', $tags);
    }

    #[Test]
    public function extractsTableFromInsert(): void
    {
        $tags = $this->extractor->extractTags('INSERT INTO users (name) VALUES (?)');

        self::assertContains('users', $tags);
    }

    #[Test]
    public function extractsTableFromUpdate(): void
    {
        $tags = $this->extractor->extractTags('UPDATE users SET name = ? WHERE id = ?');

        self::assertSame(['users'], $tags);
    }

    #[Test]
    public function extractsMultipleTables(): void
    {
        $tags = $this->extractor->extractTags(
            'SELECT u.*, o.* FROM users u JOIN orders o ON u.id = o.user_id JOIN products p ON o.product_id = p.id',
        );

        self::assertContains('users', $tags);
        self::assertContains('orders', $tags);
        self::assertContains('products', $tags);
    }

    #[Test]
    public function handlesSubqueries(): void
    {
        $tags = $this->extractor->extractTags(
            'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)',
        );

        self::assertContains('users', $tags);
        self::assertContains('orders', $tags);
    }
}
