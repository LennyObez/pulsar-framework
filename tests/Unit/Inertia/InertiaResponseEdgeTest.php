<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Inertia;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\ResponseStatus;
use Pulsar\Inertia\InertiaResponse;

/**
 * Edge case coverage for InertiaResponse.
 */
#[CoversClass(InertiaResponse::class)]
final class InertiaResponseEdgeTest extends TestCase
{
    #[Test]
    public function renderCreatesResponseWithComponent(): void
    {
        $response = InertiaResponse::render('Users/Index', ['count' => 42]);

        self::assertSame('Users/Index', $response->component());
        self::assertSame(42, $response->props()['count']);
    }

    #[Test]
    public function withSharedPropsMergesIntoExisting(): void
    {
        $response = InertiaResponse::render('Dashboard', ['metric' => 1])
            ->withSharedProps(['user' => 'Alice'])
            ->withSharedProps(['flash' => 'ok']);

        $props = $response->props();

        self::assertSame('Alice', $props['user']);
        self::assertSame('ok', $props['flash']);
        self::assertSame(1, $props['metric']);
    }

    #[Test]
    public function withUrlSetsUrl(): void
    {
        $response = InertiaResponse::render('Page')
            ->withUrl('/dashboard');

        $data = $response->toPageData();

        self::assertSame('/dashboard', $data['url']);
    }

    #[Test]
    public function withVersionSetsVersion(): void
    {
        $response = InertiaResponse::render('Page')
            ->withVersion('abc123');

        $data = $response->toPageData();

        self::assertSame('abc123', $data['version']);
    }

    #[Test]
    public function toPageDataReturnsCompleteStructure(): void
    {
        $response = InertiaResponse::render('Users/Show', ['id' => 1])
            ->withSharedProps(['auth' => true])
            ->withUrl('/users/1')
            ->withVersion('v2');

        $data = $response->toPageData();

        self::assertSame('Users/Show', $data['component']);
        self::assertSame('/users/1', $data['url']);
        self::assertSame('v2', $data['version']);
        self::assertTrue($data['props']['auth']);
        self::assertSame(1, $data['props']['id']);
    }

    #[Test]
    public function toJsonResponseReturnsValidJson(): void
    {
        $response = InertiaResponse::render('Test', ['x' => 'y']);
        $httpResponse = $response->toJsonResponse();

        self::assertSame(ResponseStatus::OK, $httpResponse->status);
        self::assertStringContainsString('application/json', $httpResponse->headers->first('Content-Type') ?? '');
        self::assertSame('true', $httpResponse->headers->first('X-Inertia'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($httpResponse->body, true);

        self::assertSame('Test', $decoded['component']);
        /** @var array<string, mixed> $decodedProps */
        $decodedProps = $decoded['props'];
        self::assertSame('y', $decodedProps['x']);
    }

    #[Test]
    public function toHtmlResponseWithRootTemplateSubstitutes(): void
    {
        $response = InertiaResponse::render('Page', ['k' => 'v']);
        $template = '<html><body><div id="app">{{ $page }}</div></body></html>';
        $httpResponse = $response->toHtmlResponse($template);

        self::assertStringContainsString('<html>', $httpResponse->body);
        self::assertStringContainsString('"component":"Page"', html_entity_decode($httpResponse->body));
        self::assertStringNotContainsString('{{ $page }}', $httpResponse->body);
    }

    #[Test]
    public function toHtmlResponseWithoutTemplateUsesDefault(): void
    {
        $response = InertiaResponse::render('Default');
        $httpResponse = $response->toHtmlResponse();

        self::assertStringContainsString('<!DOCTYPE html>', $httpResponse->body);
        self::assertStringContainsString('data-page=', $httpResponse->body);
        self::assertStringContainsString('id="app"', $httpResponse->body);
    }

    #[Test]
    public function sharedPropsAreOverriddenByPageProps(): void
    {
        $response = InertiaResponse::render('Test', ['key' => 'page'])
            ->withSharedProps(['key' => 'shared']);

        // Page props override shared props in toPageData
        $data = $response->toPageData();

        self::assertSame('page', $data['props']['key']);
    }

    #[Test]
    public function componentReturnsName(): void
    {
        $response = InertiaResponse::render('Admin/Settings');

        self::assertSame('Admin/Settings', $response->component());
    }

    #[Test]
    public function propsReturnsEmptyForNoPropsResponse(): void
    {
        $response = InertiaResponse::render('Empty');

        self::assertSame([], $response->props());
    }
}
