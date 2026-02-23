<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\LlmResponse;

#[CoversClass(LlmResponse::class)]
final class LlmResponseTest extends TestCase
{
    #[Test]
    public function constructor_sets_properties(): void
    {
        $response = new LlmResponse('Hello', 50, 100, 'stop');

        self::assertSame('Hello', $response->content);
        self::assertSame(50, $response->inputTokens);
        self::assertSame(100, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
    }

    #[Test]
    public function from_array_with_all_fields(): void
    {
        $response = LlmResponse::fromArray([
            'content' => 'Generated text',
            'input_tokens' => 150,
            'output_tokens' => 250,
            'finish_reason' => 'length',
        ]);

        self::assertSame('Generated text', $response->content);
        self::assertSame(150, $response->inputTokens);
        self::assertSame(250, $response->outputTokens);
        self::assertSame('length', $response->finishReason);
    }

    #[Test]
    public function from_array_with_empty_array(): void
    {
        $response = LlmResponse::fromArray([]);

        self::assertSame('', $response->content);
        self::assertSame(0, $response->inputTokens);
        self::assertSame(0, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
    }
}
