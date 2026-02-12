<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ReadOnlyStorageAdapter;
use Pulsar\Console\Repl\ReplSafeModeException;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageObject;

#[CoversClass(ReadOnlyStorageAdapter::class)]
final class ReadOnlyStorageAdapterTest extends TestCase
{
    private StorageAdapterInterface&Stub $inner;
    private ReadOnlyStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(StorageAdapterInterface::class);
        $this->adapter = new ReadOnlyStorageAdapter($this->inner);
    }

    #[Test]
    public function getDelegates(): void
    {
        $this->inner->method('get')->with('key')->willReturn('content');

        self::assertSame('content', $this->adapter->get('key'));
    }

    #[Test]
    public function existsDelegates(): void
    {
        $this->inner->method('exists')->with('key')->willReturn(true);

        self::assertTrue($this->adapter->exists('key'));
    }

    #[Test]
    public function listDelegates(): void
    {
        $objects = [new StorageObject('file.txt', 100, 1000)];
        $this->inner->method('list')->with('prefix/')->willReturn($objects);

        self::assertSame($objects, $this->adapter->list('prefix/'));
    }

    #[Test]
    public function temporaryUrlDelegates(): void
    {
        $this->inner->method('temporaryUrl')->with('key', 3600)->willReturn('https://example.com/tmp');

        self::assertSame('https://example.com/tmp', $this->adapter->temporaryUrl('key'));
    }

    #[Test]
    public function putThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/storage:put/');

        $this->adapter->put('key', 'content');
    }

    #[Test]
    public function deleteThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/storage:delete/');

        $this->adapter->delete('key');
    }
}
