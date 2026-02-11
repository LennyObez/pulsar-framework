<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;

#[CoversClass(StdioTransport::class)]
final class StdioTransportTest extends TestCase
{
    #[Test]
    public function maxMessageSizeConstant(): void
    {
        self::assertSame(2_097_152, StdioTransport::MAX_MESSAGE_SIZE);
    }

    #[Test]
    public function writeLineRejectsOversizedData(): void
    {
        $transport = new StdioTransport();
        $oversized = str_repeat('x', StdioTransport::MAX_MESSAGE_SIZE + 1);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Response exceeds maximum size');

        $transport->writeLine($oversized);
    }

    #[Test]
    public function writeLineDoesNotThrowForDataWithinLimit(): void
    {
        $transport = new StdioTransport();

        // Small data well within limit — writes to STDOUT (harmless in test)
        $transport->writeLine('{"jsonrpc":"2.0","result":{}}');

        // Reaching here means no exception was thrown
        self::assertSame(2_097_152, StdioTransport::MAX_MESSAGE_SIZE);
    }

    #[Test]
    public function writeLineRejectsDataExceedingLimitByOne(): void
    {
        $transport = new StdioTransport();
        $data = str_repeat('a', StdioTransport::MAX_MESSAGE_SIZE + 1);

        $this->expectException(McpException::class);

        $transport->writeLine($data);
    }

    #[Test]
    public function writeErrorDoesNotThrow(): void
    {
        $transport = new StdioTransport();

        // writeError writes to STDERR — just verify it doesn't throw
        $transport->writeError('diagnostic message');

        self::assertInstanceOf(StdioTransport::class, $transport);
    }
}
