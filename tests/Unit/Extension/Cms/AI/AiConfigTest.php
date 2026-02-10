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

    /**
     * AiConfig is a readonly DTO — it stores values as-is without validation.
     * Callers (service providers, factories) are responsible for validating
     * provider names, API key format, and connectivity before use.
     *
     * These tests document the accepted values for edge-case inputs.
     */
    #[Test]
    public function empty_api_key_is_stored_as_empty_string(): void
    {
        // An empty API key is structurally valid in the DTO — disabled-by-default
        // means it won't be used until enabled = true is explicitly set
        $config = AiConfig::fromArray(['api_key' => '']);

        self::assertSame('', $config->apiKey);
        self::assertFalse($config->enabled, 'AI must default to disabled even with empty key');
    }

    #[Test]
    public function unknown_provider_string_is_stored_verbatim(): void
    {
        // AiConfig does not validate provider names — caller validates before instantiating
        // the actual provider class. Unknown providers will fail at provider factory time.
        $config = AiConfig::fromArray([
            'enabled' => true,
            'provider' => 'unknown-provider',
        ]);

        self::assertSame('unknown-provider', $config->provider);
    }

    #[Test]
    public function boolean_coercion_for_enabled_field(): void
    {
        // fromArray coerces the enabled field to bool, so truthy strings/ints are accepted
        $configFromInt = AiConfig::fromArray(['enabled' => 1]);
        self::assertTrue($configFromInt->enabled);

        $configFromString = AiConfig::fromArray(['enabled' => '0']);
        self::assertFalse($configFromString->enabled);
    }

    #[Test]
    public function constructor_injection_preserves_all_fields(): void
    {
        // Verify the constructor (not fromArray) correctly stores all fields
        $config = new AiConfig(
            enabled: true,
            provider: 'anthropic',
            model: 'claude-opus-4-6',
            apiKey: 'sk-ant-test',
            baseUrl: 'https://api.anthropic.com/v1',
        );

        self::assertTrue($config->enabled);
        self::assertSame('anthropic', $config->provider);
        self::assertSame('claude-opus-4-6', $config->model);
        self::assertSame('sk-ant-test', $config->apiKey);
        self::assertSame('https://api.anthropic.com/v1', $config->baseUrl);
    }
}
