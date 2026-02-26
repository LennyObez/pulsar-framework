<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Cms\Config\AiConfig;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Internal\Http\AiRequestParser;
use Pulsar\Http\Message\Response;

#[CoversClass(AiRequestParser::class)]
final class AiRequestParserTest extends TestCase
{
    #[Test]
    public function parseReturnsForbiddenWhenAiDisabled(): void
    {
        $parser = new AiRequestParser(new CmsConfig(ai: new AiConfig(enabled: false)));

        $request = $this->createStub(ServerRequestInterface::class);
        $result = $parser->parse($request);

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    #[Test]
    public function parseReturnsBodyArrayWhenEnabled(): void
    {
        $parser = $this->createParser();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['prompt' => 'Hello']);

        $result = $parser->parse($request);

        self::assertIsArray($result);
        self::assertSame('Hello', $result['prompt']);
    }

    #[Test]
    public function parseReturnsEmptyArrayForNonArrayBody(): void
    {
        $parser = $this->createParser();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $result = $parser->parse($request);

        self::assertIsArray($result);
        self::assertSame([], $result);
    }

    #[Test]
    public function requireStringReturnsValueForNonEmptyString(): void
    {
        $parser = $this->createParser();

        self::assertSame('test', $parser->requireString(['field' => 'test'], 'field'));
    }

    #[Test]
    public function requireStringReturnsNullForEmptyString(): void
    {
        $parser = $this->createParser();

        self::assertNull($parser->requireString(['field' => ''], 'field'));
    }

    #[Test]
    public function requireStringReturnsNullForMissingKey(): void
    {
        $parser = $this->createParser();

        self::assertNull($parser->requireString([], 'field'));
    }

    #[Test]
    public function requireStringReturnsNullForNonStringValue(): void
    {
        $parser = $this->createParser();

        self::assertNull($parser->requireString(['field' => 42], 'field'));
    }

    #[Test]
    public function optionalStringReturnsValueWhenPresent(): void
    {
        $parser = $this->createParser();

        self::assertSame('value', $parser->optionalString(['f' => 'value'], 'f'));
    }

    #[Test]
    public function optionalStringReturnsDefaultWhenMissing(): void
    {
        $parser = $this->createParser();

        self::assertSame('default', $parser->optionalString([], 'f', 'default'));
    }

    #[Test]
    public function optionalStringReturnsDefaultForNonString(): void
    {
        $parser = $this->createParser();

        self::assertSame('fallback', $parser->optionalString(['f' => 123], 'f', 'fallback'));
    }

    #[Test]
    public function optionalIntReturnsValueWhenPresent(): void
    {
        $parser = $this->createParser();

        self::assertSame(42, $parser->optionalInt(['count' => 42], 'count', 10));
    }

    #[Test]
    public function optionalIntReturnsDefaultForMissingKey(): void
    {
        $parser = $this->createParser();

        self::assertSame(10, $parser->optionalInt([], 'count', 10));
    }

    #[Test]
    public function optionalIntReturnsDefaultForNonInt(): void
    {
        $parser = $this->createParser();

        self::assertSame(5, $parser->optionalInt(['count' => 'not_int'], 'count', 5));
    }

    #[Test]
    public function optionalStringArrayReturnsArrayWhenPresent(): void
    {
        $parser = $this->createParser();

        $result = $parser->optionalStringArray(['tags' => ['a', 'b']], 'tags');
        self::assertSame(['a', 'b'], $result);
    }

    #[Test]
    public function optionalStringArrayReturnsNullWhenMissing(): void
    {
        $parser = $this->createParser();

        self::assertNull($parser->optionalStringArray([], 'tags'));
    }

    #[Test]
    public function optionalStringArrayReturnsNullForNonArray(): void
    {
        $parser = $this->createParser();

        self::assertNull($parser->optionalStringArray(['tags' => 'not_array'], 'tags'));
    }

    #[Test]
    public function missingFieldResponseReturns422(): void
    {
        $parser = $this->createParser();

        $response = $parser->missingFieldResponse('prompt');

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('prompt', $body);
    }

    private function createParser(): AiRequestParser
    {
        return new AiRequestParser(new CmsConfig(ai: new AiConfig(enabled: true)));
    }
}
