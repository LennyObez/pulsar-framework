<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Monitor\QueryClassification;
use Pulsar\Database\Monitor\QueryClassifier;

#[CoversClass(QueryClassifier::class)]
final class QueryClassifierTest extends TestCase
{
    #[Test]
    public function selectClassifiedCorrectly(): void
    {
        self::assertSame(QueryClassification::Select, QueryClassifier::classify('SELECT * FROM users'));
        self::assertSame(QueryClassification::Select, QueryClassifier::classify('SHOW TABLES'));
        self::assertSame(QueryClassification::Select, QueryClassifier::classify('DESCRIBE users'));
        self::assertSame(QueryClassification::Select, QueryClassifier::classify('EXPLAIN SELECT * FROM users'));
    }

    #[Test]
    public function insertClassifiedCorrectly(): void
    {
        self::assertSame(QueryClassification::Insert, QueryClassifier::classify('INSERT INTO users (name) VALUES (?)'));
    }

    #[Test]
    public function updateClassifiedCorrectly(): void
    {
        self::assertSame(QueryClassification::Update, QueryClassifier::classify('UPDATE users SET name = ? WHERE id = ?'));
    }

    #[Test]
    public function deleteClassifiedCorrectly(): void
    {
        self::assertSame(QueryClassification::Delete, QueryClassifier::classify('DELETE FROM users WHERE id = ?'));
    }

    #[Test]
    public function ddlClassifiedCorrectly(): void
    {
        self::assertSame(QueryClassification::Ddl, QueryClassifier::classify('CREATE TABLE users (id INT)'));
        self::assertSame(QueryClassification::Ddl, QueryClassifier::classify('ALTER TABLE users ADD COLUMN name VARCHAR(255)'));
        self::assertSame(QueryClassification::Ddl, QueryClassifier::classify('DROP TABLE users'));
        self::assertSame(QueryClassification::Ddl, QueryClassifier::classify('TRUNCATE TABLE users'));
    }

    #[Test]
    public function caseInsensitive(): void
    {
        self::assertSame(QueryClassification::Select, QueryClassifier::classify('select * from users'));
        self::assertSame(QueryClassification::Insert, QueryClassifier::classify('insert into users (name) values (?)'));
        self::assertSame(QueryClassification::Update, QueryClassifier::classify('update users set name = ?'));
        self::assertSame(QueryClassification::Delete, QueryClassifier::classify('delete from users'));
        self::assertSame(QueryClassification::Ddl, QueryClassifier::classify('create table test (id int)'));
    }

    #[Test]
    public function leadingWhitespaceHandled(): void
    {
        self::assertSame(QueryClassification::Select, QueryClassifier::classify('   SELECT * FROM users'));
        self::assertSame(QueryClassification::Insert, QueryClassifier::classify("\t\nINSERT INTO users (name) VALUES (?)"));
    }

    #[Test]
    public function cteWithSelectClassifiedAsSelect(): void
    {
        $sql = 'WITH active_users AS (SELECT * FROM users WHERE active = 1) SELECT * FROM active_users';
        self::assertSame(QueryClassification::Select, QueryClassifier::classify($sql));
    }
}
