<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;

#[CoversClass(McpException::class)]
final class McpExceptionTest extends TestCase
{
    #[Test]
    public function toolNotFoundIncludesToolName(): void
    {
        $exception = McpException::toolNotFound('pulsar.routes.list');

        self::assertStringContainsString('pulsar.routes.list', $exception->getMessage());
    }

    #[Test]
    public function protocolErrorIncludesCodeAndMessage(): void
    {
        $exception = McpException::protocolError('Invalid JSON', -32700);

        self::assertSame(-32700, $exception->getCode());
        self::assertStringContainsString('Invalid JSON', $exception->getMessage());
        self::assertStringContainsString('-32700', $exception->getMessage());
    }

    #[Test]
    public function executionFailedIncludesToolAndReason(): void
    {
        $exception = McpException::executionFailed('tests', 'timeout exceeded');

        self::assertStringContainsString('tests', $exception->getMessage());
        self::assertStringContainsString('timeout exceeded', $exception->getMessage());
    }

    #[Test]
    public function outputTruncatedIncludesToolName(): void
    {
        $exception = McpException::outputTruncated('analysis');

        self::assertStringContainsString('analysis', $exception->getMessage());
        self::assertStringContainsString('truncated', $exception->getMessage());
    }
}
