<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Http3;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Http3\PushResource;
use Pulsar\Http\Http3\ServerPush;

#[CoversClass(ServerPush::class)]
#[CoversClass(PushResource::class)]
final class ServerPushTest extends TestCase
{
    #[Test]
    public function stylesheetGeneratesPreloadHeader(): void
    {
        $push = new ServerPush();
        $push->stylesheet('/css/app.css');

        $headers = $push->toLinkHeaders();

        self::assertCount(1, $headers);
        self::assertSame('</css/app.css>; rel=preload; as=style', $headers[0]);
    }

    #[Test]
    public function scriptGeneratesPreloadHeader(): void
    {
        $push = new ServerPush();
        $push->script('/js/app.js');

        $headers = $push->toLinkHeaders();

        self::assertSame('</js/app.js>; rel=preload; as=script', $headers[0]);
    }

    #[Test]
    public function fontIncludesCrossorigin(): void
    {
        $push = new ServerPush();
        $push->font('/fonts/inter.woff2');

        $headers = $push->toLinkHeaders();

        self::assertStringContainsString('crossorigin', $headers[0]);
        self::assertStringContainsString('as=font', $headers[0]);
    }

    #[Test]
    public function imageGeneratesPreloadHeader(): void
    {
        $push = new ServerPush();
        $push->image('/img/hero.webp');

        $headers = $push->toLinkHeaders();

        self::assertSame('</img/hero.webp>; rel=preload; as=image', $headers[0]);
    }

    #[Test]
    public function noPushAttributeIsIncluded(): void
    {
        $push = new ServerPush();
        $push->stylesheet('/css/app.css', noPush: true);

        $headers = $push->toLinkHeaders();

        self::assertStringContainsString('nopush', $headers[0]);
    }

    #[Test]
    public function resourceWithCustomType(): void
    {
        $push = new ServerPush();
        $push->resource('/data.json', 'fetch', crossOrigin: true);

        $headers = $push->toLinkHeaders();

        self::assertSame('</data.json>; rel=preload; as=fetch; crossorigin', $headers[0]);
    }

    #[Test]
    public function multipleResourcesCombine(): void
    {
        $push = new ServerPush();
        $push->stylesheet('/css/app.css');
        $push->script('/js/app.js');
        $push->font('/fonts/inter.woff2');

        self::assertSame(3, $push->count());
        self::assertFalse($push->isEmpty());

        $combined = $push->toCombinedHeader();

        self::assertStringContainsString('</css/app.css>', $combined);
        self::assertStringContainsString('</js/app.js>', $combined);
    }

    #[Test]
    public function emptyPush(): void
    {
        $push = new ServerPush();

        self::assertTrue($push->isEmpty());
        self::assertSame(0, $push->count());
        self::assertSame([], $push->toLinkHeaders());
    }

    #[Test]
    public function resourcesReturnsAllPushResources(): void
    {
        $push = new ServerPush();
        $push->stylesheet('/a.css');
        $push->script('/b.js');

        $resources = $push->resources();

        self::assertCount(2, $resources);
        self::assertContainsOnlyInstancesOf(PushResource::class, $resources);
    }

    #[Test]
    public function pushResourceToHeaderValue(): void
    {
        $resource = new PushResource(path: '/app.css', as: 'style');

        self::assertSame('</app.css>; rel=preload; as=style', $resource->toHeaderValue());
    }

    #[Test]
    public function pushResourceWithNoPushAndCrossorigin(): void
    {
        $resource = new PushResource(path: '/font.woff2', as: 'font', noPush: true, crossOrigin: true);

        $header = $resource->toHeaderValue();

        self::assertStringContainsString('crossorigin', $header);
        self::assertStringContainsString('nopush', $header);
    }
}
