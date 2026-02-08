<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\SqlNormalizer;

use function strlen;

#[CoversClass(SqlNormalizer::class)]
final class SqlNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReplacesNumericLiteralsWithPlaceholder(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 123';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id = ?', $result);
    }

    #[Test]
    public function normalizeReplacesFloatLiteralsWithPlaceholder(): void
    {
        $sql = 'SELECT * FROM products WHERE price > 19.99';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from products where price > ?', $result);
    }

    #[Test]
    public function normalizeReplacesStringSingleQuotedLiteralsWithPlaceholder(): void
    {
        $sql = "SELECT * FROM users WHERE name = 'John Doe'";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where name = ?', $result);
    }

    #[Test]
    public function normalizeReplacesMultipleStringLiterals(): void
    {
        $sql = "SELECT * FROM users WHERE first_name = 'John' AND last_name = 'Doe'";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where first_name = ? and last_name = ?', $result);
    }

    #[Test]
    public function normalizeHandlesInsertWithValues(): void
    {
        $sql = "INSERT INTO users (name, email, age) VALUES ('John', 'john@example.com', 25)";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('insert into users (name, email, age) values (?, ?, ?)', $result);
    }

    #[Test]
    public function normalizeHandlesUpdateWithSet(): void
    {
        $sql = "UPDATE users SET name = 'Jane', age = 30 WHERE id = 42";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('update users set name = ?, age = ? where id = ?', $result);
    }

    #[Test]
    public function normalizeHandlesInClauseWithNumbers(): void
    {
        $sql = 'SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id in (?, ?, ?, ?, ?)', $result);
    }

    #[Test]
    public function normalizeHandlesInClauseWithStrings(): void
    {
        $sql = "SELECT * FROM users WHERE status IN ('active', 'pending', 'inactive')";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where status in (?, ?, ?)', $result);
    }

    #[Test]
    public function normalizePreservesTableNames(): void
    {
        $sql = 'SELECT * FROM users123 WHERE active = 1';

        $result = SqlNormalizer::normalize($sql);

        // Table name 'users123' should be preserved, only the '1' should become '?'
        self::assertStringContainsString('users', $result);
    }

    #[Test]
    public function normalizePreservesColumnNames(): void
    {
        $sql = 'SELECT id, user_name, email_address FROM users WHERE active = 1';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('id', $result);
        self::assertStringContainsString('user_name', $result);
        self::assertStringContainsString('email_address', $result);
    }

    #[Test]
    public function normalizeHandlesLikePattern(): void
    {
        $sql = "SELECT * FROM users WHERE name LIKE '%search%'";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where name like ?', $result);
    }

    #[Test]
    public function normalizeHandlesLikePatternWithPrefix(): void
    {
        $sql = "SELECT * FROM users WHERE email LIKE 'john%'";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where email like ?', $result);
    }

    #[Test]
    public function normalizeHandlesLikePatternWithSuffix(): void
    {
        $sql = "SELECT * FROM users WHERE email LIKE '%@example.com'";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where email like ?', $result);
    }

    #[Test]
    public function normalizeCollapsesMultipleSpaces(): void
    {
        $sql = 'SELECT *   FROM   users    WHERE id = 1';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id = ?', $result);
    }

    #[Test]
    public function normalizeCollapsesNewlines(): void
    {
        $sql = "SELECT *\nFROM users\nWHERE id = 1";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id = ?', $result);
    }

    #[Test]
    public function normalizeCollapsesTabs(): void
    {
        $sql = "SELECT *\tFROM\tusers\tWHERE id = 1";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id = ?', $result);
    }

    #[Test]
    public function normalizeTrimsLeadingAndTrailingWhitespace(): void
    {
        $sql = '   SELECT * FROM users WHERE id = 1   ';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id = ?', $result);
    }

    #[Test]
    public function normalizeLowercasesSqlKeywords(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1 AND status = 1 OR deleted = 0';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where id = ? and status = ? or deleted = ?', $result);
    }

    #[Test]
    public function normalizeHandlesJoinKeywords(): void
    {
        $sql = 'SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id WHERE users.id = 1';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame(
            'select * from users left join orders on users.id = orders.user_id where users.id = ?',
            $result,
        );
    }

    #[Test]
    public function normalizeHandlesBetweenClause(): void
    {
        $sql = 'SELECT * FROM orders WHERE created_at BETWEEN 1000 AND 2000';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from orders where created_at between ? and ?', $result);
    }

    #[Test]
    public function normalizeHandlesLimitAndOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10 OFFSET 20';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users limit ? offset ?', $result);
    }

    #[Test]
    public function normalizeHandlesOrderByAndGroupBy(): void
    {
        $sql = 'SELECT status, COUNT(*) FROM users GROUP BY status ORDER BY status ASC';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('group by', $result);
        self::assertStringContainsString('order by', $result);
        self::assertStringContainsString('asc', $result);
    }

    #[Test]
    public function normalizeHandlesDeleteQuery(): void
    {
        $sql = 'DELETE FROM users WHERE id = 42';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('delete from users where id = ?', $result);
    }

    #[Test]
    public function normalizeHandlesNullKeyword(): void
    {
        $sql = 'SELECT * FROM users WHERE deleted_at IS NULL';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where deleted_at is null', $result);
    }

    #[Test]
    public function normalizeHandlesNotNullKeyword(): void
    {
        $sql = 'SELECT * FROM users WHERE email IS NOT NULL';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where email is not null', $result);
    }

    #[Test]
    public function normalizeHandlesExistsSubquery(): void
    {
        $sql = 'SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('exists', $result);
        self::assertStringContainsString('select ?', $result);
    }

    #[Test]
    public function normalizeHandlesDistinct(): void
    {
        $sql = 'SELECT DISTINCT status FROM users';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select distinct status from users', $result);
    }

    #[Test]
    public function normalizeHandlesCaseWhenThenElse(): void
    {
        $sql = "SELECT CASE WHEN status = 1 THEN 'Active' ELSE 'Inactive' END FROM users";

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('case', $result);
        self::assertStringContainsString('when', $result);
        self::assertStringContainsString('then', $result);
        self::assertStringContainsString('else', $result);
        self::assertStringContainsString('end', $result);
    }

    #[Test]
    public function normalizeHandlesUnion(): void
    {
        $sql = 'SELECT id FROM users UNION SELECT id FROM admins';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select id from users union select id from admins', $result);
    }

    #[Test]
    public function normalizeHandlesStringWithEscapedQuotes(): void
    {
        $sql = "SELECT * FROM users WHERE name = 'O''Brien'";

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('?', $result);
    }

    #[Test]
    public function normalizeHandlesEmptyString(): void
    {
        $sql = "SELECT * FROM users WHERE name = ''";

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('select * from users where name = ?', $result);
    }

    #[Test]
    public function normalizeHandlesComplexQuery(): void
    {
        $sql = <<<'SQL'
            SELECT u.id, u.name, COUNT(o.id) as order_count
            FROM users u
            LEFT JOIN orders o ON u.id = o.user_id
            WHERE u.status = 'active'
            AND u.created_at > 1609459200
            AND o.total > 100.50
            GROUP BY u.id, u.name
            HAVING COUNT(o.id) > 5
            ORDER BY order_count DESC
            LIMIT 10
            SQL;

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('select', $result);
        self::assertStringContainsString('from', $result);
        self::assertStringContainsString('left join', $result);
        self::assertStringContainsString('where', $result);
        self::assertStringContainsString('group by', $result);
        self::assertStringContainsString('having', $result);
        self::assertStringContainsString('order by', $result);
        self::assertStringContainsString('limit', $result);
        self::assertStringNotContainsString('active', $result);
        self::assertStringNotContainsString('1609459200', $result);
        self::assertStringNotContainsString('100.50', $result);
    }

    #[Test]
    public function fingerprintReturnsSha256Hash(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 123';

        $result = SqlNormalizer::fingerprint($sql);

        self::assertSame(64, strlen($result)); // SHA-256 produces 64 hex chars
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result);
    }

    #[Test]
    public function fingerprintIsDeterministic(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 123';

        $result1 = SqlNormalizer::fingerprint($sql);
        $result2 = SqlNormalizer::fingerprint($sql);

        self::assertSame($result1, $result2);
    }

    #[Test]
    public function fingerprintProducesSameHashForStructurallyIdenticalQueries(): void
    {
        $sql1 = 'SELECT * FROM users WHERE id = 123';
        $sql2 = 'SELECT * FROM users WHERE id = 456';
        $sql3 = "SELECT * FROM users WHERE id = 'abc'";

        $fingerprint1 = SqlNormalizer::fingerprint($sql1);
        $fingerprint2 = SqlNormalizer::fingerprint($sql2);
        $fingerprint3 = SqlNormalizer::fingerprint($sql3);

        self::assertSame($fingerprint1, $fingerprint2);
        // String literal also normalizes to ? so same fingerprint
        self::assertSame($fingerprint1, $fingerprint3);
    }

    #[Test]
    public function fingerprintProducesDifferentHashForDifferentStructures(): void
    {
        $sql1 = 'SELECT * FROM users WHERE id = 123';
        $sql2 = 'SELECT * FROM users WHERE name = 123';

        $fingerprint1 = SqlNormalizer::fingerprint($sql1);
        $fingerprint2 = SqlNormalizer::fingerprint($sql2);

        self::assertNotSame($fingerprint1, $fingerprint2);
    }

    #[Test]
    #[DataProvider('queryTypeProvider')]
    public function detectQueryTypeReturnsCorrectType(string $sql, string $expectedType): void
    {
        $result = SqlNormalizer::detectQueryType($sql);

        self::assertSame($expectedType, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function queryTypeProvider(): iterable
    {
        yield 'SELECT query' => ['SELECT * FROM users', 'SELECT'];
        yield 'SELECT with lowercase' => ['select * from users', 'SELECT'];
        yield 'SELECT with leading space' => ['  SELECT * FROM users', 'SELECT'];
        yield 'INSERT query' => ['INSERT INTO users (name) VALUES (?)', 'INSERT'];
        yield 'UPDATE query' => ['UPDATE users SET name = ?', 'UPDATE'];
        yield 'DELETE query' => ['DELETE FROM users WHERE id = ?', 'DELETE'];
        yield 'CREATE TABLE' => ['CREATE TABLE users (id INT)', 'DDL'];
        yield 'ALTER TABLE' => ['ALTER TABLE users ADD COLUMN email VARCHAR(255)', 'DDL'];
        yield 'DROP TABLE' => ['DROP TABLE users', 'DDL'];
        yield 'BEGIN transaction' => ['BEGIN', 'OTHER'];
        yield 'COMMIT transaction' => ['COMMIT', 'OTHER'];
        yield 'ROLLBACK transaction' => ['ROLLBACK', 'OTHER'];
        yield 'PRAGMA query' => ['PRAGMA table_info(users)', 'OTHER'];
        yield 'EXPLAIN query' => ['EXPLAIN SELECT * FROM users', 'OTHER'];
        yield 'WITH CTE' => ['WITH cte AS (SELECT * FROM users) SELECT * FROM cte', 'OTHER'];
        yield 'REPLACE query' => ['REPLACE INTO users (id, name) VALUES (?, ?)', 'OTHER'];
    }

    #[Test]
    public function detectQueryTypeHandlesEmptyString(): void
    {
        $result = SqlNormalizer::detectQueryType('');

        self::assertSame('OTHER', $result);
    }

    #[Test]
    public function detectQueryTypeHandlesWhitespaceOnly(): void
    {
        $result = SqlNormalizer::detectQueryType('   ');

        self::assertSame('OTHER', $result);
    }

    #[Test]
    public function normalizeHandlesPragmaQueries(): void
    {
        $sql = 'PRAGMA table_info(users)';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('pragma', $result);
    }

    #[Test]
    public function normalizeHandlesVacuum(): void
    {
        $sql = 'VACUUM';

        $result = SqlNormalizer::normalize($sql);

        self::assertSame('vacuum', $result);
    }

    #[Test]
    public function normalizeHandlesCreateTable(): void
    {
        $sql = 'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('create', $result);
        self::assertStringContainsString('table', $result);
        self::assertStringContainsString('primary', $result);
        self::assertStringContainsString('key', $result);
        self::assertStringContainsString('autoincrement', $result);
    }

    #[Test]
    public function normalizeHandlesCreateIndex(): void
    {
        $sql = 'CREATE INDEX idx_users_email ON users (email)';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('create', $result);
        self::assertStringContainsString('index', $result);
    }

    #[Test]
    public function normalizeHandlesDropTable(): void
    {
        $sql = 'DROP TABLE IF EXISTS users CASCADE';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('drop', $result);
        self::assertStringContainsString('table', $result);
        self::assertStringContainsString('if', $result);
        self::assertStringContainsString('exists', $result);
        self::assertStringContainsString('cascade', $result);
    }

    #[Test]
    public function normalizeHandlesNegativeNumbers(): void
    {
        $sql = 'SELECT * FROM accounts WHERE balance < -100';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('-?', $result);
    }

    #[Test]
    public function normalizeHandlesTrueAndFalseKeywords(): void
    {
        $sql = 'SELECT * FROM users WHERE active = TRUE AND deleted = FALSE';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('true', $result);
        self::assertStringContainsString('false', $result);
    }

    #[Test]
    public function normalizeHandlesWithRecursive(): void
    {
        $sql = 'WITH RECURSIVE tree AS (SELECT 1) SELECT * FROM tree';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('with', $result);
        self::assertStringContainsString('recursive', $result);
    }

    #[Test]
    public function normalizeHandlesOnConflict(): void
    {
        $sql = "INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Test')";

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('replace', $result);
    }

    #[Test]
    public function normalizeHandlesTemporaryTable(): void
    {
        $sql = 'CREATE TEMPORARY TABLE temp_users AS SELECT * FROM users';

        $result = SqlNormalizer::normalize($sql);

        self::assertStringContainsString('temporary', $result);
    }
}
