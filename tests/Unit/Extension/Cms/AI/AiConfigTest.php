<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\AiConfig;

#[CoversClass(AiConfig::class)]
final class AiConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_disabled(): void
    {
        $config = new AiConfig();

        self::assertFalse($config->enabled);
        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('', $config->apiKey);
        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function from_array_with_all_fields(): void
    {
        $config = AiConfig::fromArray([
            'enabled' => true,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'api_key' => 'sk-test-key',
            'base_url' => 'https://custom.api.example.com/v1',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('anthropic', $config->provider);
        self::assertSame('claude-sonnet-4-6', $config->model);
        self::assertSame('sk-test-key', $config->apiKey);
        self::assertSame('https://custom.api.example.com/v1', $config->baseUrl);
    }

    #[Test]
    public function from_array_with_empty_array_returns_defaults(): void
    {
        $config = AiConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('', $config->apiKey);
        self::assertSame('', $config->baseUrl);
    }

    #[Test]
    public function from_array_with_partial_fields(): void
    {
        $config = AiConfig::fromArray([
            'enabled' => true,
            'api_key' => 'sk-partial',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('openai', $config->provider);
        self::assertSame('', $config->model);
        self::assertSame('sk-partial', $config->apiKey);
        self::assertSame('', $config->baseUrl);
    }
}
