<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Migration\DiffResult;

final class DiffResultTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $result = new DiffResult(
            version: '20260320120000',
            content: '<?php ...',
            upStatements: ['CREATE TABLE users (...)'],
            downStatements: ['DROP TABLE users'],
            hasChanges: true,
        );

        self::assertSame('20260320120000', $result->version);
        self::assertSame('<?php ...', $result->content);
        self::assertSame(['CREATE TABLE users (...)'], $result->upStatements);
        self::assertSame(['DROP TABLE users'], $result->downStatements);
        self::assertTrue($result->hasChanges);
    }

    #[Test]
    public function noChangesFactoryReturnsEmptyResult(): void
    {
        $result = DiffResult::noChanges();

        self::assertSame('', $result->version);
        self::assertSame('', $result->content);
        self::assertSame([], $result->upStatements);
        self::assertSame([], $result->downStatements);
        self::assertFalse($result->hasChanges);
    }
}
