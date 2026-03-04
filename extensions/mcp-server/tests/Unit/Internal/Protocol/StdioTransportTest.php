<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;

use function strlen;

#[CoversClass(StdioTransport::class)]
final class StdioTransportTest extends TestCase
{
    #[Test]
    public function maxMessageSizeIsDefinedAt2MB(): void
    {
        self::assertSame(2_097_152, StdioTransport::MAX_MESSAGE_SIZE);
    }

    #[Test]
    public function writeLineRejectsOversizedMessages(): void
    {
        $transport = new StdioTransport();
        $oversized = str_repeat('A', StdioTransport::MAX_MESSAGE_SIZE + 1);

        $this->expectException(\Pulsar\Extension\McpServer\Exception\McpException::class);
        $this->expectExceptionMessage('exceeds maximum size');

        $transport->writeLine($oversized);
    }

    #[Test]
    public function writeLineAcceptsExactMaxSizeMessage(): void
    {
        $transport = new StdioTransport();
        $exact = str_repeat('A', StdioTransport::MAX_MESSAGE_SIZE);

        // writeLine writes to STDOUT directly via fwrite.
        // Verify it does not throw for exact-size messages.
        $transport->writeLine($exact);

        self::assertSame(StdioTransport::MAX_MESSAGE_SIZE, strlen($exact));
    }

    #[Test]
    public function writeLineAcceptsEmptyString(): void
    {
        $transport = new StdioTransport();

        // Should not throw
        $transport->writeLine('');

        self::assertSame(0, strlen(''));
    }

    #[Test]
    public function writeErrorDoesNotThrow(): void
    {
        $transport = new StdioTransport();

        // writeError writes to STDERR; verify it completes without exception
        $transport->writeError('diagnostic message');

        self::assertInstanceOf(StdioTransport::class, $transport);
    }
}
