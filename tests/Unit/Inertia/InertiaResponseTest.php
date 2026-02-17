<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Inertia;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\ResponseStatus;
use Pulsar\Inertia\InertiaResponse;

#[CoversClass(InertiaResponse::class)]
final class InertiaResponseTest extends TestCase
{
    #[Test]
    public function render_creates_response_with_component_and_props(): void
    {
        $response = InertiaResponse::render('Users/Show', ['user' => ['name' => 'Alice']]);

        self::assertSame('Users/Show', $response->component());
        self::assertSame(['name' => 'Alice'], $response->props()['user']);
    }

    #[Test]
    public function to_page_data_includes_all_fields(): void
    {
        $pageData = InertiaResponse::render('Dashboard', ['count' => 42])
            ->withUrl('/dashboard')
            ->withVersion('v1.2.3')
            ->toPageData();

        self::assertSame('Dashboard', $pageData['component']);
        self::assertSame(42, $pageData['props']['count']);
        self::assertSame('/dashboard', $pageData['url']);
        self::assertSame('v1.2.3', $pageData['version']);
    }

    #[Test]
    public function shared_props_merge_with_page_props(): void
    {
        $response = InertiaResponse::render('Page', ['title' => 'Hello'])
            ->withSharedProps(['auth' => ['user' => 'Bob'], 'flash' => []]);

        $props = $response->props();

        self::assertSame('Hello', $props['title']);
        self::assertSame(['user' => 'Bob'], $props['auth']);
        self::assertSame([], $props['flash']);
    }

    #[Test]
    public function page_props_override_shared_props(): void
    {
        $response = InertiaResponse::render('Page', ['key' => 'page-value'])
            ->withSharedProps(['key' => 'shared-value']);

        self::assertSame('page-value', $response->props()['key']);
    }

    #[Test]
    public function to_json_response_format(): void
    {
        $httpResponse = InertiaResponse::render('Users/Index', ['users' => []])
            ->withUrl('/users')
            ->withVersion('abc123')
            ->toJsonResponse();

        self::assertSame(ResponseStatus::OK, $httpResponse->status);
        self::assertSame('application/json; charset=utf-8', $httpResponse->headers->first('Content-Type'));
        self::assertSame('true', $httpResponse->headers->first('X-Inertia'));
        self::assertSame('X-Inertia', $httpResponse->headers->first('Vary'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($httpResponse->body, true);
        self::assertSame('Users/Index', $decoded['component']);
        /** @var array<string, mixed> $props */
        $props = $decoded['props'];
        self::assertSame([], $props['users']);
    }

    #[Test]
    public function to_html_response_embeds_page_data(): void
    {
        $httpResponse = InertiaResponse::render('Home', ['greeting' => 'Hi'])
            ->toHtmlResponse();

        self::assertSame(ResponseStatus::OK, $httpResponse->status);
        self::assertStringContainsString('data-page=', $httpResponse->body);
        self::assertStringContainsString('Hi', $httpResponse->body);
    }

    #[Test]
    public function to_html_response_with_custom_template(): void
    {
        $template = '<html><body><div id="root">{{ $page }}</div></body></html>';

        $httpResponse = InertiaResponse::render('Custom', [])
            ->toHtmlResponse($template);

        self::assertStringContainsString('<div id="root">', $httpResponse->body);
        self::assertStringNotContainsString('{{ $page }}', $httpResponse->body);
    }

    #[Test]
    public function immutability_preserved(): void
    {
        $original = InertiaResponse::render('A', []);
        $modified = $original->withUrl('/modified');

        $originalData = $original->toPageData();
        $modifiedData = $modified->toPageData();

        self::assertSame('', $originalData['url']);
        self::assertSame('/modified', $modifiedData['url']);
    }

    #[Test]
    public function shared_props_are_cumulative(): void
    {
        $response = InertiaResponse::render('P', [])
            ->withSharedProps(['a' => 1])
            ->withSharedProps(['b' => 2]);

        $props = $response->props();

        self::assertSame(1, $props['a']);
        self::assertSame(2, $props['b']);
    }
}
