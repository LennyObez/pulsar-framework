<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Runtime\FilesystemReader;
use Pulsar\Deploy\Runtime\FilesystemReaderInterface;

#[CoversClass(FilesystemReader::class)]
final class FilesystemReaderTest extends TestCase
{
    private FilesystemReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FilesystemReader();
    }

    #[Test]
    public function implementsInterface(): void
    {
        self::assertInstanceOf(FilesystemReaderInterface::class, $this->reader);
    }

    #[Test]
    public function isReadableReturnsTrueForExistingFile(): void
    {
        // This test file itself is readable
        self::assertTrue($this->reader->isReadable(__FILE__));
    }

    #[Test]
    public function isReadableReturnsFalseForNonExistentFile(): void
    {
        self::assertFalse($this->reader->isReadable('/nonexistent/path/that/does/not/exist.txt'));
    }

    #[Test]
    public function isReadableReturnsTrueForDirectory(): void
    {
        self::assertTrue($this->reader->isReadable(__DIR__));
    }
}
