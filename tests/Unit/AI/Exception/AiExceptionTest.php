<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiException;
use RuntimeException;

#[CoversClass(AiException::class)]
final class AiExceptionTest extends TestCase
{
    #[Test]
    public function apiErrorIncludesProviderAndMessage(): void
    {
        $ex = AiException::apiError('openai', 'Rate limit exceeded', 429);

        self::assertStringContainsString('openai', $ex->getMessage());
        self::assertStringContainsString('Rate limit exceeded', $ex->getMessage());
        self::assertSame(429, $ex->getCode());
    }

    #[Test]
    public function connectionFailedIncludesProviderAndUrl(): void
    {
        $ex = AiException::connectionFailed('anthropic', 'https://api.anthropic.com/v1');

        self::assertStringContainsString('anthropic', $ex->getMessage());
        self::assertStringContainsString('https://api.anthropic.com/v1', $ex->getMessage());
    }

    #[Test]
    public function providerNotConfiguredIncludesProvider(): void
    {
        $ex = AiException::providerNotConfigured('ollama');

        self::assertStringContainsString('ollama', $ex->getMessage());
        self::assertStringContainsString('not configured', $ex->getMessage());
    }

    #[Test]
    public function unsupportedCapabilityIncludesModelAndCapability(): void
    {
        $ex = AiException::unsupportedCapability('claude-sonnet-4-6', 'embeddings');

        self::assertStringContainsString('claude-sonnet-4-6', $ex->getMessage());
        self::assertStringContainsString('embeddings', $ex->getMessage());
    }

    #[Test]
    public function invalidResponseIncludesProvider(): void
    {
        $ex = AiException::invalidResponse('openai');

        self::assertStringContainsString('openai', $ex->getMessage());
        self::assertStringContainsString('invalid JSON', $ex->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $ex = AiException::apiError('test', 'msg');

        self::assertInstanceOf(RuntimeException::class, $ex);
    }
}
