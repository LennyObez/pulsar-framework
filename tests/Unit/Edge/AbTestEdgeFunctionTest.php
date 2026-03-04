<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Edge\AbTestEdgeFunction;
use Pulsar\Edge\EdgeRequest;

#[CoversClass(AbTestEdgeFunction::class)]
final class AbTestEdgeFunctionTest extends TestCase
{
    #[Test]
    public function returns_null_for_empty_variants(): void
    {
        $fn = new AbTestEdgeFunction('exp1', []);

        $request = new EdgeRequest(method: 'GET', url: '/', path: '/');

        self::assertNull($fn->handle($request));
    }

    #[Test]
    public function assigns_variant_from_ip_deterministically(): void
    {
        $fn = new AbTestEdgeFunction('pricing', [
            'control' => '/pricing',
            'variant-a' => '/pricing-new',
        ]);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            ip: '10.0.0.1',
        );

        $response = $fn->handle($request);

        if ($response === null) {
            self::fail('Expected non-null EdgeResponse from handle()');
        }

        self::assertSame(302, $response->statusCode);

        // Same IP should always get same variant
        $response2 = $fn->handle($request);

        if ($response2 === null) {
            self::fail('Expected non-null EdgeResponse from second handle()');
        }

        self::assertSame(
            $response->headers['Location'],
            $response2->headers['Location'],
        );
    }

    #[Test]
    public function sets_cookie_on_new_assignment(): void
    {
        $fn = new AbTestEdgeFunction('exp', [
            'a' => '/a',
            'b' => '/b',
        ]);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            ip: '1.2.3.4',
        );

        $response = $fn->handle($request);

        self::assertNotNull($response);
        self::assertArrayHasKey('px_ab_exp', $response->cookies);
        self::assertContains($response->cookies['px_ab_exp'], ['a', 'b']);
    }

    #[Test]
    public function follows_existing_cookie_assignment(): void
    {
        $fn = new AbTestEdgeFunction('exp', [
            'a' => '/version-a',
            'b' => '/version-b',
        ]);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            cookies: ['px_ab_exp' => 'b'],
        );

        $response = $fn->handle($request);

        self::assertNotNull($response);
        self::assertSame('/version-b', $response->headers['Location']);
    }

    #[Test]
    public function ignores_invalid_cookie_variant(): void
    {
        $fn = new AbTestEdgeFunction('exp', [
            'a' => '/a',
            'b' => '/b',
        ]);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            cookies: ['px_ab_exp' => 'nonexistent'],
            ip: '5.5.5.5',
        );

        $response = $fn->handle($request);

        self::assertNotNull($response);
        // Should fall through to IP-based assignment
        self::assertArrayHasKey('px_ab_exp', $response->cookies);
    }

    #[Test]
    public function returns_null_when_already_on_variant_url(): void
    {
        $fn = new AbTestEdgeFunction('exp', [
            'a' => '/page-a',
            'b' => '/page-b',
        ]);

        $request = new EdgeRequest(
            method: 'GET',
            url: '/page-a',
            path: '/page-a',
            cookies: ['px_ab_exp' => 'a'],
        );

        self::assertNull($fn->handle($request));
    }

    #[Test]
    public function custom_cookie_name(): void
    {
        $fn = new AbTestEdgeFunction(
            experimentName: 'test',
            variants: ['x' => '/x'],
            cookieName: 'my_ab',
        );

        $request = new EdgeRequest(
            method: 'GET',
            url: '/',
            path: '/',
            cookies: ['my_ab_test' => 'x'],
        );

        $response = $fn->handle($request);
        // Already on '/' not '/x', should redirect
        self::assertNotNull($response);
        self::assertSame('/x', $response->headers['Location']);
    }

    #[Test]
    public function name_includes_experiment(): void
    {
        $fn = new AbTestEdgeFunction('pricing-v2', []);

        self::assertSame('ab-test-pricing-v2', $fn->name());
    }
}
