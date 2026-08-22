<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Seeder;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Seeder\SeederEntry;

final class SeederEntryTest extends TestCase
{
    #[Test]
    public function constructorStoresNameAndPath(): void
    {
        $entry = new SeederEntry('UserSeeder', '/path/to/UserSeeder.php');

        self::assertSame('UserSeeder', $entry->name);
        self::assertSame('/path/to/UserSeeder.php', $entry->path);
    }
}
