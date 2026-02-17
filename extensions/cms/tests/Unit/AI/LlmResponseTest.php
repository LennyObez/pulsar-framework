<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\LlmResponse;

#[CoversClass(LlmResponse::class)]
final class LlmResponseTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $response = new LlmResponse(
            content: 'Generated text',
            inputTokens: 150,
            outputTokens: 250,
            finishReason: 'stop',
        );

        self::assertSame('Generated text', $response->content);
        self::assertSame(150, $response->inputTokens);
        self::assertSame(250, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $response = LlmResponse::fromArray([
            'content' => 'AI output',
            'input_tokens' => 100,
            'output_tokens' => 200,
            'finish_reason' => 'length',
        ]);

        self::assertSame('AI output', $response->content);
        self::assertSame(100, $response->inputTokens);
        self::assertSame(200, $response->outputTokens);
        self::assertSame('length', $response->finishReason);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $response = LlmResponse::fromArray([]);

        self::assertSame('', $response->content);
        self::assertSame(0, $response->inputTokens);
        self::assertSame(0, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
    }

    #[Test]
    public function fromArrayWithNumericStringTokens(): void
    {
        $response = LlmResponse::fromArray([
            'input_tokens' => '500',
            'output_tokens' => '1000',
        ]);

        self::assertSame(500, $response->inputTokens);
        self::assertSame(1000, $response->outputTokens);
    }
}
