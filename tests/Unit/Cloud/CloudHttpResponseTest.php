<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudHttpResponse;

#[CoversClass(CloudHttpResponse::class)]
final class CloudHttpResponseTest extends TestCase
{
    #[Test]
    public function constructionPreservesValues(): void
    {
        $response = new CloudHttpResponse(200, '{"ok":true}');

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame([], $response->headers);
    }

    #[Test]
    public function headerLookupIsCaseInsensitive(): void
    {
        $response = new CloudHttpResponse(200, '', ['etag' => '"abc123"', 'content-type' => 'application/xml']);

        // Headers are stored lowercased; lookup lowercases the query.
        self::assertSame('"abc123"', $response->header('ETag'));
        self::assertSame('"abc123"', $response->header('etag'));
        self::assertSame('application/xml', $response->header('Content-Type'));
        self::assertNull($response->header('X-Absent'));
    }

    #[Test]
    #[DataProvider('successStatusCodes')]
    public function isSuccessForTwoHundredRange(int $code, bool $expected): void
    {
        $response = new CloudHttpResponse($code, '');

        self::assertSame($expected, $response->isSuccess());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function successStatusCodes(): iterable
    {
        yield '199 is not success' => [199, false];
        yield '200 is success' => [200, true];
        yield '201 is success' => [201, true];
        yield '204 is success' => [204, true];
        yield '299 is success' => [299, true];
        yield '300 is not success' => [300, false];
        yield '400 is not success' => [400, false];
        yield '404 is not success' => [404, false];
        yield '500 is not success' => [500, false];
    }
}
