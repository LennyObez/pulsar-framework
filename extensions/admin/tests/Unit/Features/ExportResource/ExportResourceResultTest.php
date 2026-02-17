<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ExportResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceResult;

#[CoversClass(ExportResourceResult::class)]
final class ExportResourceResultTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $result = new ExportResourceResult(
            content: 'id,name\n1,John',
            mimeType: 'text/csv',
            filename: 'users_2026-03-28.csv',
            evidenceHash: 'abc123def456',
            rowCount: 1,
        );

        self::assertSame('id,name\n1,John', $result->content);
        self::assertSame('text/csv', $result->mimeType);
        self::assertSame('users_2026-03-28.csv', $result->filename);
        self::assertSame('abc123def456', $result->evidenceHash);
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function empty_export(): void
    {
        $result = new ExportResourceResult(
            content: '',
            mimeType: 'application/json',
            filename: 'empty.json',
            evidenceHash: 'hash',
            rowCount: 0,
        );

        self::assertSame('', $result->content);
        self::assertSame(0, $result->rowCount);
    }
}
