<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\AcceptValue;
use Pulsar\Http\ContentNegotiation;

#[CoversClass(ContentNegotiation::class)]
#[CoversClass(AcceptValue::class)]
final class ContentNegotiationTest extends TestCase
{
    #[Test]
    public function negotiateTypeReturnsFirstAvailableWhenAcceptIsEmpty(): void
    {
        $request = $this->requestWithHeader('Accept', '');

        $result = ContentNegotiation::negotiateType($request, ['application/json', 'text/html']);

        self::assertSame('application/json', $result);
    }

    #[Test]
    public function negotiateTypeReturnsFirstAvailableForWildcard(): void
    {
        $request = $this->requestWithHeader('Accept', '*/*');

        $result = ContentNegotiation::negotiateType($request, ['text/html', 'application/json']);

        self::assertSame('text/html', $result);
    }

    #[Test]
    public function negotiateTypeSelectsHighestQuality(): void
    {
        $request = $this->requestWithHeader(
            'Accept',
            'text/html;q=0.9, application/json;q=1.0',
        );

        $result = ContentNegotiation::negotiateType(
            $request,
            ['text/html', 'application/json'],
        );

        self::assertSame('application/json', $result);
    }

    #[Test]
    public function negotiateTypeHandlesSubtypeWildcard(): void
    {
        $request = $this->requestWithHeader('Accept', 'text/*');

        $result = ContentNegotiation::negotiateType(
            $request,
            ['application/json', 'text/html'],
        );

        self::assertSame('text/html', $result);
    }

    #[Test]
    public function negotiateTypeReturnsNullWhenNoMatch(): void
    {
        $request = $this->requestWithHeader('Accept', 'image/png');

        $result = ContentNegotiation::negotiateType(
            $request,
            ['text/html', 'application/json'],
        );

        self::assertNull($result);
    }

    #[Test]
    public function negotiateTypeSkipsQualityZero(): void
    {
        $request = $this->requestWithHeader(
            'Accept',
            'text/html;q=0, application/json',
        );

        $result = ContentNegotiation::negotiateType(
            $request,
            ['text/html', 'application/json'],
        );

        self::assertSame('application/json', $result);
    }

    #[Test]
    public function negotiateLanguageSelectsPreferred(): void
    {
        $request = $this->requestWithHeader(
            'Accept-Language',
            'fr;q=0.8, en;q=1.0, de;q=0.5',
        );

        $result = ContentNegotiation::negotiateLanguage(
            $request,
            ['de', 'fr', 'en'],
        );

        self::assertSame('en', $result);
    }

    #[Test]
    public function negotiateEncodingSelectsPreferred(): void
    {
        $request = $this->requestWithHeader(
            'Accept-Encoding',
            'gzip;q=1.0, br;q=0.9, identity;q=0.5',
        );

        $result = ContentNegotiation::negotiateEncoding(
            $request,
            ['br', 'gzip', 'identity'],
        );

        self::assertSame('gzip', $result);
    }

    #[Test]
    public function parseQualityValuesSortsCorrectly(): void
    {
        $values = ContentNegotiation::parseQualityValues(
            'text/html, application/json;q=0.9, text/plain;q=0.8',
        );

        self::assertCount(3, $values);
        self::assertSame('text/html', $values[0]->value);
        self::assertSame(1.0, $values[0]->quality);
        self::assertSame('application/json', $values[1]->value);
        self::assertSame(0.9, $values[1]->quality);
        self::assertSame('text/plain', $values[2]->value);
        self::assertSame(0.8, $values[2]->quality);
    }

    #[Test]
    public function parseQualityValuesHandlesParameters(): void
    {
        $values = ContentNegotiation::parseQualityValues(
            'text/html;level=1;q=0.7',
        );

        self::assertCount(1, $values);
        self::assertSame('text/html', $values[0]->value);
        self::assertSame(0.7, $values[0]->quality);
        self::assertSame(['level' => '1'], $values[0]->parameters);
    }

    #[Test]
    public function parseQualityValuesClampsToValidRange(): void
    {
        $values = ContentNegotiation::parseQualityValues('text/html;q=1.5, text/plain;q=-0.1');

        self::assertSame(1.0, $values[0]->quality);
        self::assertSame(0.0, $values[1]->quality);
    }

    #[Test]
    public function specificTypesWinOverWildcardsAtSameQuality(): void
    {
        $values = ContentNegotiation::parseQualityValues(
            'text/html, text/*, */*',
        );

        self::assertSame('text/html', $values[0]->value);
        self::assertSame('text/*', $values[1]->value);
        self::assertSame('*/*', $values[2]->value);
    }

    #[Test]
    public function negotiateTypeReturnsNullForEmptyAvailable(): void
    {
        $request = $this->requestWithHeader('Accept', 'text/html');

        self::assertNull(ContentNegotiation::negotiateType($request, []));
    }

    #[Test]
    public function acceptValueStoresAllProperties(): void
    {
        $av = new AcceptValue('text/html', 0.9, 3, ['level' => '2']);

        self::assertSame('text/html', $av->value);
        self::assertSame(0.9, $av->quality);
        self::assertSame(3, $av->order);
        self::assertSame(['level' => '2'], $av->parameters);
    }

    private function requestWithHeader(string $header, string $value): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($value);

        return $request;
    }
}
