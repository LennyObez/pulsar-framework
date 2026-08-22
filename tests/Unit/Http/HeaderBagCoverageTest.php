<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;

#[CoversClass(HeaderBag::class)]
final class HeaderBagCoverageTest extends TestCase
{
    #[Test]
    public function fromServerExtractsContentMd5(): void
    {
        $server = [
            'CONTENT_MD5' => 'abc123',
        ];

        $bag = HeaderBag::fromServer($server);

        self::assertTrue($bag->has('Content-Md5'));
        self::assertSame('abc123', $bag->first('Content-Md5'));
    }

    #[Test]
    public function fromServerSkipsNonStringValues(): void
    {
        $server = [
            'HTTP_HOST' => 'example.com',
            'HTTP_NUMERIC' => 42,           // non-string → skipped
            'HTTP_ARRAY' => ['a', 'b'],     // non-string → skipped
            'HTTP_NULL' => null,            // non-string → skipped
        ];

        $bag = HeaderBag::fromServer($server);

        self::assertTrue($bag->has('Host'));
        self::assertFalse($bag->has('Numeric'));
        self::assertFalse($bag->has('Array'));
        self::assertFalse($bag->has('Null'));
    }

    #[Test]
    public function withAcceptsArrayValue(): void
    {
        $bag = new HeaderBag();
        $new = $bag->with('Accept', ['text/html', 'application/json']);

        self::assertSame(['text/html', 'application/json'], $new->get('Accept'));
    }
}
