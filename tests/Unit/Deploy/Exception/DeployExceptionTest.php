<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Deploy\Exception\DeployException;
use RuntimeException;

#[CoversClass(DeployException::class)]
final class DeployExceptionTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $exception = DeployException::checkFailed('test', 'reason');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function check_failed_includes_name_and_reason(): void
    {
        $exception = DeployException::checkFailed('opcache', 'Extension not loaded');

        self::assertSame('Deploy check "opcache" failed: Extension not loaded', $exception->getMessage());
    }

    #[Test]
    public function invalid_environment_includes_environment_name(): void
    {
        $exception = DeployException::invalidEnvironment('banana');

        self::assertSame(
            'Invalid deployment environment "banana". Valid environments: local, staging, production',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function report_generation_failed_includes_reason(): void
    {
        $exception = DeployException::reportGenerationFailed('Disk full');

        self::assertSame('Deploy report generation failed: Disk full', $exception->getMessage());
    }

    #[Test]
    public function check_failed_with_empty_strings(): void
    {
        $exception = DeployException::checkFailed('', '');

        self::assertSame('Deploy check "" failed: ', $exception->getMessage());
    }

    #[Test]
    public function invalid_environment_with_special_characters(): void
    {
        $exception = DeployException::invalidEnvironment('prod-v2.1');

        self::assertStringContainsString('prod-v2.1', $exception->getMessage());
    }
}
