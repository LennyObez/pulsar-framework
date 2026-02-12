<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Exception\ResourceLeakException;
use Pulsar\Runtime\ResourceEntry;
use RuntimeException;

#[CoversClass(ResourceLeakException::class)]
final class ResourceLeakExceptionTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $entry = new ResourceEntry('conn-1', 'database', 'MySQL connection', 1700000000.1234);
        $exception = ResourceLeakException::unclosedResource($entry);

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function unclosed_resource_factory_includes_all_entry_fields(): void
    {
        $entry = new ResourceEntry('conn-1', 'database', 'MySQL connection', 1700000000.1234);
        $exception = ResourceLeakException::unclosedResource($entry);

        self::assertStringContainsString('conn-1', $exception->getMessage());
        self::assertStringContainsString('database', $exception->getMessage());
        self::assertStringContainsString('MySQL connection', $exception->getMessage());
        self::assertStringContainsString('1700000000.1234', $exception->getMessage());
        self::assertStringContainsString('Unclosed resource detected', $exception->getMessage());
    }

    #[Test]
    public function multiple_leaks_factory_includes_count_and_all_entries(): void
    {
        $entries = [
            new ResourceEntry('conn-1', 'database', 'MySQL connection', 1700000000.0),
            new ResourceEntry('stream-1', 'file', 'Log file handle', 1700000001.0),
            new ResourceEntry('redis-1', 'cache', 'Redis connection', 1700000002.0),
        ];

        $exception = ResourceLeakException::multipleLeaks($entries);
        $message = $exception->getMessage();

        self::assertStringContainsString('3 unclosed resource(s) detected', $message);
        self::assertStringContainsString('[database] conn-1', $message);
        self::assertStringContainsString('[file] stream-1', $message);
        self::assertStringContainsString('[cache] redis-1', $message);
    }

    #[Test]
    public function multiple_leaks_factory_with_single_entry(): void
    {
        $entries = [
            new ResourceEntry('conn-1', 'database', 'MySQL connection', 1700000000.0),
        ];

        $exception = ResourceLeakException::multipleLeaks($entries);

        self::assertStringContainsString('1 unclosed resource(s) detected', $exception->getMessage());
        self::assertStringContainsString('[database] conn-1', $exception->getMessage());
    }
}
