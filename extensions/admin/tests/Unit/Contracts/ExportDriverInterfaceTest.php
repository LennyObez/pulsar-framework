<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\ExportDriverInterface;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use ReflectionClass;

#[CoversNothing]
final class ExportDriverInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(ExportDriverInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('format'));
        self::assertTrue($reflection->hasMethod('mimeType'));
        self::assertTrue($reflection->hasMethod('fileExtension'));
        self::assertTrue($reflection->hasMethod('export'));
    }

    #[Test]
    public function stubReturnsConfiguredValues(): void
    {
        $stub = $this->createStub(ExportDriverInterface::class);
        $stub->method('format')->willReturn(ExportFormat::Csv);
        $stub->method('mimeType')->willReturn('text/csv');
        $stub->method('fileExtension')->willReturn('csv');
        $stub->method('export')->willReturn('id,name');

        self::assertSame(ExportFormat::Csv, $stub->format());
        self::assertSame('text/csv', $stub->mimeType());
        self::assertSame('csv', $stub->fileExtension());
        self::assertSame('id,name', $stub->export(['id', 'name'], []));
    }
}
