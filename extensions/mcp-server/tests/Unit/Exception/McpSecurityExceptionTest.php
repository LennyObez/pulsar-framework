<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;

final class McpSecurityExceptionTest extends TestCase
{
    #[Test]
    public function toolNotAllowedIncludesToolName(): void
    {
        $e = McpSecurityException::toolNotAllowed('run_tests');

        self::assertStringContainsString('run_tests', $e->getMessage());
    }

    #[Test]
    public function pathNotAllowedIncludesPath(): void
    {
        $e = McpSecurityException::pathNotAllowed('/etc/passwd');

        self::assertStringContainsString('/etc/passwd', $e->getMessage());
    }

    #[Test]
    public function rateLimitedIncludesToolAndRetryAfter(): void
    {
        $e = McpSecurityException::rateLimited('run_tests', 30);

        self::assertStringContainsString('run_tests', $e->getMessage());
        self::assertStringContainsString('30', $e->getMessage());
    }

    #[Test]
    public function concurrencyLimitedHasMessage(): void
    {
        $e = McpSecurityException::concurrencyLimited();

        self::assertStringContainsString('concurrent', $e->getMessage());
    }

    #[Test]
    public function environmentBlockedIncludesMode(): void
    {
        $e = McpSecurityException::environmentBlocked('production');

        self::assertStringContainsString('production', $e->getMessage());
    }
}
