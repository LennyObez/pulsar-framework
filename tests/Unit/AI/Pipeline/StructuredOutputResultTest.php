<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Pipeline\StructuredOutputResult;

#[CoversClass(StructuredOutputResult::class)]
final class StructuredOutputResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $response = new AiResponse(content: '{"name":"Alice"}', inputTokens: 10, outputTokens: 5, finishReason: 'stop');
        $result = new StructuredOutputResult(
            data: ['name' => 'Alice'],
            rawContent: '{"name":"Alice"}',
            isValid: true,
            response: $response,
        );

        self::assertSame(['name' => 'Alice'], $result->data);
        self::assertSame('{"name":"Alice"}', $result->rawContent);
        self::assertTrue($result->isValid);
        self::assertSame($response, $result->response);
    }

    #[Test]
    public function getReturnsValueByKey(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new StructuredOutputResult(
            data: ['name' => 'Bob', 'age' => 30, 'active' => true],
            rawContent: '',
            isValid: true,
            response: $response,
        );

        self::assertSame('Bob', $result->get('name'));
        self::assertSame(30, $result->get('age'));
        self::assertTrue($result->get('active'));
    }

    #[Test]
    public function getReturnsDefaultForMissingKey(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new StructuredOutputResult(
            data: ['name' => 'Charlie'],
            rawContent: '',
            isValid: true,
            response: $response,
        );

        self::assertNull($result->get('nonexistent'));
        self::assertSame('fallback', $result->get('missing', 'fallback'));
        self::assertSame(0, $result->get('count', 0));
    }

    #[Test]
    public function getReturnsNullDefaultByDefault(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new StructuredOutputResult(data: [], rawContent: '', isValid: false, response: $response);

        self::assertNull($result->get('anything'));
    }

    #[Test]
    public function invalidResultHasEmptyDataAndFalseIsValid(): void
    {
        $response = AiResponse::error('Parse failed');
        $result = new StructuredOutputResult(
            data: [],
            rawContent: 'not json',
            isValid: false,
            response: $response,
        );

        self::assertSame([], $result->data);
        self::assertFalse($result->isValid);
        self::assertSame('not json', $result->rawContent);
        self::assertTrue($result->response->isError());
    }

    #[Test]
    public function getNullValueFallsToDefaultDueToCoalescing(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new StructuredOutputResult(
            data: ['nullable_field' => null],
            rawContent: '',
            isValid: true,
            response: $response,
        );

        // The ?? operator treats null as missing, so default is returned
        self::assertSame('default', $result->get('nullable_field', 'default'));
    }

    #[Test]
    public function getReturnsFalsyValuesWithoutFallingToDefault(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new StructuredOutputResult(
            data: ['zero' => 0, 'empty' => '', 'false_val' => false],
            rawContent: '',
            isValid: true,
            response: $response,
        );

        // Non-null falsy values are returned (not the default)
        self::assertSame(0, $result->get('zero', 99));
        self::assertSame('', $result->get('empty', 'fallback'));
        self::assertFalse($result->get('false_val', true));
    }
}
