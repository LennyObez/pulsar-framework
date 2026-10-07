<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\StorageObject;

#[CoversClass(StorageObject::class)]
final class StorageObjectTest extends TestCase
{
    #[Test]
    public function holdsAllProperties(): void
    {
        $obj = new StorageObject(
            key: 'uploads/avatar.png',
            size: 102400,
            lastModified: 1709827200,
            contentType: 'image/png',
        );

        self::assertSame('uploads/avatar.png', $obj->key);
        self::assertSame(102400, $obj->size);
        self::assertSame(1709827200, $obj->lastModified);
        self::assertSame('image/png', $obj->contentType);
    }

    #[Test]
    public function contentTypeDefaultsToNull(): void
    {
        $obj = new StorageObject('file.bin', 500, 1709827200);

        self::assertNull($obj->contentType);
    }
}
