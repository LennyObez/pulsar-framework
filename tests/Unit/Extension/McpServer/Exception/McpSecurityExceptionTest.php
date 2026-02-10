<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;

#[CoversClass(McpSecurityException::class)]
final class McpSecurityExceptionTest extends TestCase
{
    #[Test]
    public function toolNotAllowedIncludesToolName(): void
    {
        $exception = McpSecurityException::toolNotAllowed('run_tests');

        self::assertStringContainsString('run_tests', $exception->getMessage());
    }

    #[Test]
    public function pathNotAllowedIncludesPath(): void
    {
        $exception = McpSecurityException::pathNotAllowed('/etc/passwd');

        self::assertStringContainsString('/etc/passwd', $exception->getMessage());
    }

    #[Test]
    public function rateLimitedIncludesRetryAfter(): void
    {
        $exception = McpSecurityException::rateLimited('pulsar.tests.run', 30);

        self::assertStringContainsString('pulsar.tests.run', $exception->getMessage());
        self::assertStringContainsString('30', $exception->getMessage());
    }

    #[Test]
    public function concurrencyLimitedHasDescriptiveMessage(): void
    {
        $exception = McpSecurityException::concurrencyLimited();

        self::assertStringContainsString('concurrent', $exception->getMessage());
    }

    #[Test]
    public function environmentBlockedIncludesMode(): void
    {
        $exception = McpSecurityException::environmentBlocked('production');

        self::assertStringContainsString('production', $exception->getMessage());
    }
}
