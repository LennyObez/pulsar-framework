<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\AiConfig;

#[CoversClass(AiConfig::class)]
final class AiConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledWithOpenAi(): void
    {
        $config = new AiConfig();

        self::assertFalse($config->enabled);
        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('', $config->apiKey);
        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = AiConfig::fromArray([
            'enabled' => true,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'api_key' => 'sk-test-key',
            'base_url' => 'https://api.anthropic.com',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('anthropic', $config->provider);
        self::assertSame('claude-sonnet-4-6', $config->model);
        self::assertSame('sk-test-key', $config->apiKey);
        self::assertSame('https://api.anthropic.com', $config->baseUrl);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = AiConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('', $config->apiKey);
    }

    #[Test]
    public function fromArrayIgnoresNonStringFields(): void
    {
        $config = AiConfig::fromArray([
            'provider' => 42,
            'model' => false,
            'api_key' => ['key'],
            'base_url' => 123,
        ]);

        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('', $config->apiKey);
        self::assertSame('', $config->baseUrl);
    }
}
