<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Config\AiConfig;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Config\ProviderCredentials;

#[CoversClass(AiConfig::class)]
#[CoversClass(ProviderCredentials::class)]
#[CoversClass(AiRequestOptions::class)]
final class AiConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new AiConfig();

        self::assertFalse($config->enabled);
        self::assertSame('anthropic', $config->defaultProvider);
        self::assertSame('', $config->defaultModel);
        self::assertSame(0.7, $config->defaultTemperature);
        self::assertSame(1024, $config->defaultMaxTokens);
        self::assertSame([], $config->providers);
    }

    #[Test]
    public function fromArrayParsesFullConfig(): void
    {
        $config = AiConfig::fromArray([
            'enabled' => true,
            'default_provider' => 'openai',
            'default_model' => 'gpt-4o',
            'default_temperature' => 0.5,
            'default_max_tokens' => 2048,
            'providers' => [
                'openai' => [
                    'api_key' => 'sk-test-key',
                    'base_url' => 'https://api.openai.com/v1',
                    'organization' => 'org-123',
                ],
                'anthropic' => [
                    'api_key' => 'sk-ant-test',
                ],
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('openai', $config->defaultProvider);
        self::assertSame('gpt-4o', $config->defaultModel);
        self::assertSame(0.5, $config->defaultTemperature);
        self::assertSame(2048, $config->defaultMaxTokens);
        self::assertCount(2, $config->providers);
    }

    #[Test]
    public function credentialsForReturnsProviderOrNull(): void
    {
        $config = AiConfig::fromArray([
            'providers' => [
                'openai' => ['api_key' => 'sk-test'],
            ],
        ]);

        $creds = $config->credentialsFor('openai');

        self::assertNotNull($creds);
        self::assertSame('sk-test', $creds->apiKey);

        self::assertNull($config->credentialsFor('anthropic'));
    }

    #[Test]
    public function fromArrayHandlesEmptyInput(): void
    {
        $config = AiConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('anthropic', $config->defaultProvider);
        self::assertSame([], $config->providers);
    }

    #[Test]
    public function providerCredentialsFromArray(): void
    {
        $creds = ProviderCredentials::fromArray([
            'api_key' => 'key123',
            'base_url' => 'https://custom.api',
            'organization' => 'org-abc',
        ]);

        self::assertSame('key123', $creds->apiKey);
        self::assertSame('https://custom.api', $creds->baseUrl);
        self::assertSame('org-abc', $creds->organization);
    }

    #[Test]
    public function providerCredentialsDefaultsAreEmpty(): void
    {
        $creds = new ProviderCredentials();

        self::assertSame('', $creds->apiKey);
        self::assertSame('', $creds->baseUrl);
        self::assertSame('', $creds->organization);
    }

    #[Test]
    public function requestOptionsDefaults(): void
    {
        $options = new AiRequestOptions();

        self::assertNull($options->temperature);
        self::assertNull($options->maxTokens);
        self::assertNull($options->systemPrompt);
        self::assertNull($options->model);
        self::assertSame(120, $options->timeoutSeconds);
        self::assertSame([], $options->tools);
        self::assertNull($options->responseFormat);
    }

    #[Test]
    public function requestOptionsAcceptsAllParameters(): void
    {
        $options = new AiRequestOptions(
            temperature: 0.3,
            maxTokens: 4096,
            systemPrompt: 'Be helpful.',
            model: 'claude-sonnet-4-6',
            timeoutSeconds: 60,
            responseFormat: 'json',
        );

        self::assertSame(0.3, $options->temperature);
        self::assertSame(4096, $options->maxTokens);
        self::assertSame('Be helpful.', $options->systemPrompt);
        self::assertSame('claude-sonnet-4-6', $options->model);
        self::assertSame(60, $options->timeoutSeconds);
        self::assertSame('json', $options->responseFormat);
    }
}
