<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;

#[CoversClass(Response::class)]
final class ResponseVaryTest extends TestCase
{
    #[Test]
    public function varyOnAddsTheHeaderWhenAbsent(): void
    {
        $response = Response::text('x')->varyOn('Accept-Language');

        self::assertSame('Accept-Language', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function varyOnPreservesAnExistingVaryValue(): void
    {
        $response = Response::text('x')->withHeader('Vary', 'Cookie')->varyOn('Accept-Language');

        self::assertSame('Cookie, Accept-Language', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function varyOnIsIdempotent(): void
    {
        $response = Response::text('x')->varyOn('Accept-Language')->varyOn('accept-language');

        self::assertSame('Accept-Language', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function varyOnAcceptsMultipleFields(): void
    {
        $response = Response::text('x')->varyOn('Accept-Language', 'Cookie');

        self::assertSame('Accept-Language, Cookie', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function varyOnReturnsTheSameInstanceWhenNothingChanges(): void
    {
        $response = Response::text('x')->varyOn('Accept-Language');

        self::assertSame($response, $response->varyOn('Accept-Language'));
    }
}
