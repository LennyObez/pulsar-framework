<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\ToolDefinition;

#[CoversClass(AiRequestOptions::class)]
final class AiRequestOptionsTest extends TestCase
{
    #[Test]
    public function defaultValuesAreApplied(): void
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
    public function constructorAcceptsAllParameters(): void
    {
        $tool = new ToolDefinition('search', 'Search the web', ['type' => 'object']);

        $options = new AiRequestOptions(
            temperature: 0.5,
            maxTokens: 2048,
            systemPrompt: 'You are a helpful assistant.',
            model: 'claude-sonnet-4-6',
            timeoutSeconds: 60,
            tools: [$tool],
            responseFormat: 'json',
        );

        self::assertSame(0.5, $options->temperature);
        self::assertSame(2048, $options->maxTokens);
        self::assertSame('You are a helpful assistant.', $options->systemPrompt);
        self::assertSame('claude-sonnet-4-6', $options->model);
        self::assertSame(60, $options->timeoutSeconds);
        self::assertCount(1, $options->tools);
        self::assertSame('search', $options->tools[0]->name);
        self::assertSame('json', $options->responseFormat);
    }

    #[Test]
    public function temperatureBoundaryValues(): void
    {
        $zero = new AiRequestOptions(temperature: 0.0);
        self::assertSame(0.0, $zero->temperature);

        $max = new AiRequestOptions(temperature: 2.0);
        self::assertSame(2.0, $max->temperature);
    }

    #[Test]
    public function textResponseFormat(): void
    {
        $options = new AiRequestOptions(responseFormat: 'text');

        self::assertSame('text', $options->responseFormat);
    }

    #[Test]
    public function multipleToolsCanBeProvided(): void
    {
        $tools = [
            new ToolDefinition('search', 'Search', []),
            new ToolDefinition('calculate', 'Calculate', []),
            new ToolDefinition('translate', 'Translate', []),
        ];

        $options = new AiRequestOptions(tools: $tools);

        self::assertCount(3, $options->tools);
        self::assertSame('calculate', $options->tools[1]->name);
    }
}
