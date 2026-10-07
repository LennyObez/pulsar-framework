<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Extension\Cms\Internal\Persistence\SchemaErrors;
use RuntimeException;

/**
 * Regression guard for the fresh-install request-path crash: the CMS owns the
 * catch-all route, so a missing schema (unmigrated DB) must resolve to a 404 /
 * welcome page, while a genuine database failure must still surface as a 500.
 * SchemaErrors draws that line; misclassifying a real outage as "missing table"
 * would silently hide an incident.
 */
#[CoversClass(SchemaErrors::class)]
final class SchemaErrorsTest extends TestCase
{
    #[Test]
    public function detectsSqliteMissingTable(): void
    {
        $error = DatabaseException::queryFailed(
            'SELECT * FROM cms_redirects',
            new RuntimeException('SQLSTATE[HY000]: General error: 1 no such table: cms_redirects'),
        );

        self::assertTrue(SchemaErrors::isMissingTable($error));
    }

    #[Test]
    public function detectsMysqlMissingTable(): void
    {
        $error = DatabaseException::queryFailed(
            'SELECT * FROM cms_redirects',
            new RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'app.cms_redirects' doesn't exist"),
        );

        self::assertTrue(SchemaErrors::isMissingTable($error));
    }

    #[Test]
    public function detectsPostgresMissingTable(): void
    {
        $error = DatabaseException::queryFailed(
            'SELECT * FROM cms_redirects',
            new RuntimeException('SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "cms_redirects" does not exist'),
        );

        self::assertTrue(SchemaErrors::isMissingTable($error));
    }

    #[Test]
    public function readsTheWrappedDriverMessageNotOnlyTheOuter(): void
    {
        // DatabaseException::queryFailed wraps the driver exception; the
        // missing-table marker lives in the previous exception's message.
        $error = DatabaseException::queryFailed(
            'SELECT * FROM cms_menus',
            new RuntimeException('no such table: cms_menus'),
        );

        self::assertTrue(SchemaErrors::isMissingTable($error));
    }

    #[Test]
    public function doesNotMisclassifyARealDatabaseOutage(): void
    {
        // A genuine outage must NOT be treated as a missing table, otherwise the
        // CMS would mask a real incident behind a 404.
        $error = DatabaseException::queryFailed(
            'SELECT * FROM cms_redirects',
            new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
        );

        self::assertFalse(SchemaErrors::isMissingTable($error));
    }

    #[Test]
    public function doesNotMisclassifyAConstraintViolation(): void
    {
        $error = DatabaseException::queryFailed(
            'INSERT INTO cms_redirects ...',
            new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
        );

        self::assertFalse(SchemaErrors::isMissingTable($error));
    }
}
