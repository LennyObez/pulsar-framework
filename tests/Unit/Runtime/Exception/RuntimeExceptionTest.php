<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Exception\RuntimeException;

#[CoversClass(RuntimeException::class)]
final class RuntimeExceptionTest extends TestCase
{
    #[Test]
    public function socket_error_factory(): void
    {
        $e = RuntimeException::socketError('connection refused');
        self::assertStringContainsString('Socket error', $e->getMessage());
        self::assertStringContainsString('connection refused', $e->getMessage());
    }

    #[Test]
    public function parse_error_factory(): void
    {
        $e = RuntimeException::parseError('malformed request line');
        self::assertStringContainsString('HTTP parse error', $e->getMessage());
        self::assertStringContainsString('malformed request line', $e->getMessage());
    }

    #[Test]
    public function payload_too_large_factory(): void
    {
        $e = RuntimeException::payloadTooLarge(10_485_760);
        self::assertStringContainsString('10485760', $e->getMessage());
        self::assertStringContainsString('maximum size', $e->getMessage());
    }

    #[Test]
    public function headers_too_large_factory(): void
    {
        $e = RuntimeException::headersTooLarge(8192);
        self::assertStringContainsString('8192', $e->getMessage());
        self::assertStringContainsString('headers exceed', $e->getMessage());
    }

    #[Test]
    public function binding_refused_factory(): void
    {
        $e = RuntimeException::bindingRefused('0.0.0.0', 8080, 'address in use');
        self::assertStringContainsString('0.0.0.0:8080', $e->getMessage());
        self::assertStringContainsString('address in use', $e->getMessage());
    }

    #[Test]
    public function extension_missing_factory(): void
    {
        $e = RuntimeException::extensionMissing('sockets');
        self::assertStringContainsString('sockets', $e->getMessage());
        self::assertStringContainsString('not loaded', $e->getMessage());
    }

    #[Test]
    public function fatal_error_factory(): void
    {
        $e = RuntimeException::fatalError('segmentation fault');
        self::assertStringContainsString('Fatal runtime error', $e->getMessage());
        self::assertStringContainsString('segmentation fault', $e->getMessage());
    }
}
