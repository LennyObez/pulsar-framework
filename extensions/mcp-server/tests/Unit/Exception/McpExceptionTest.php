<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;

final class McpExceptionTest extends TestCase
{
    #[Test]
    public function toolNotFoundIncludesToolName(): void
    {
        $e = McpException::toolNotFound('read_routes');

        self::assertStringContainsString('read_routes', $e->getMessage());
    }

    #[Test]
    public function protocolErrorIncludesCodeAndMessage(): void
    {
        $e = McpException::protocolError('Parse error', -32700);

        self::assertSame(-32700, $e->getCode());
        self::assertStringContainsString('Parse error', $e->getMessage());
        self::assertStringContainsString('-32700', $e->getMessage());
    }

    #[Test]
    public function executionFailedIncludesToolAndReason(): void
    {
        $e = McpException::executionFailed('run_tests', 'timeout');

        self::assertStringContainsString('run_tests', $e->getMessage());
        self::assertStringContainsString('timeout', $e->getMessage());
    }

    #[Test]
    public function outputTruncatedIncludesToolName(): void
    {
        $e = McpException::outputTruncated('read_config');

        self::assertStringContainsString('read_config', $e->getMessage());
        self::assertStringContainsString('truncated', $e->getMessage());
    }
}
