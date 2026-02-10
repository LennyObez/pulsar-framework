<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ---- Boundary / negative tests ----

    #[Test]
    public function total_tokens_is_sum_of_input_and_output(): void
    {
        $response = new LlmResponse('content', 100, 200, 'stop');

        self::assertSame(300, $response->inputTokens + $response->outputTokens);
    }

    #[Test]
    public function zero_token_counts_are_preserved(): void
    {
        $response = new LlmResponse('content', 0, 0, 'stop');

        self::assertSame(0, $response->inputTokens);
        self::assertSame(0, $response->outputTokens);
    }

    #[Test]
    public function empty_content_is_preserved(): void
    {
        $response = new LlmResponse('', 0, 0, 'stop');

        self::assertSame('', $response->content);
    }

    #[Test]
    public function from_array_casts_numeric_strings_to_int(): void
    {
        $response = LlmResponse::fromArray([
            'content' => 'text',
            'input_tokens' => '75',
            'output_tokens' => '125',
            'finish_reason' => 'length',
        ]);

        self::assertSame(75, $response->inputTokens);
        self::assertSame(125, $response->outputTokens);
    }

    #[Test]
    public function from_array_unknown_keys_are_ignored(): void
    {
        $response = LlmResponse::fromArray([
            'content' => 'text',
            'unknown_field' => 'ignored',
            'another_unknown' => 999,
        ]);

        // Only known fields are mapped; defaults apply for missing ones
        self::assertSame('text', $response->content);
        self::assertSame(0, $response->inputTokens);
        self::assertSame('stop', $response->finishReason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function finishReasonProvider(): iterable
    {
        yield 'stop' => ['stop'];
        yield 'length' => ['length'];
        yield 'content_filter' => ['content_filter'];
        yield 'tool_calls' => ['tool_calls'];
    }

    #[Test]
    #[DataProvider('finishReasonProvider')]
    public function finish_reason_is_preserved(string $reason): void
    {
        $response = new LlmResponse('content', 0, 0, $reason);

        self::assertSame($reason, $response->finishReason);
    }
}
