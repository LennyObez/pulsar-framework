<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\StorageDriver;

#[CoversNothing]
final class StorageDriverTest extends TestCase
{
    #[Test]
    public function backingValues(): void
    {
        self::assertSame('local', StorageDriver::Local->value);
        self::assertSame('s3', StorageDriver::S3->value);
        self::assertSame('memory', StorageDriver::Memory->value);
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(3, StorageDriver::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(StorageDriver::tryFrom('gcs'));
    }
}
