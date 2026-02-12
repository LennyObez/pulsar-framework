<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Exception\EncryptedColumnQueryException;
use Pulsar\Extension\Orm\Exception\OrmException;

final class EncryptedColumnQueryExceptionTest extends TestCase
{
    #[Test]
    public function extendsOrmException(): void
    {
        self::assertInstanceOf(
            OrmException::class,
            EncryptedColumnQueryException::filteredWithoutBlindIndex('email'),
        );
    }

    #[Test]
    public function filteredWithoutBlindIndexIncludesColumn(): void
    {
        $e = EncryptedColumnQueryException::filteredWithoutBlindIndex('ssn');

        self::assertStringContainsString('ssn', $e->getMessage());
        self::assertStringContainsString('blind index', $e->getMessage());
    }

    #[Test]
    public function orderedEncryptedColumnIncludesColumn(): void
    {
        $e = EncryptedColumnQueryException::orderedEncryptedColumn('secret');

        self::assertStringContainsString('secret', $e->getMessage());
        self::assertStringContainsString('ORDER BY', $e->getMessage());
    }
}
