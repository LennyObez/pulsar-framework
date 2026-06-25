<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteUrlGenerator;

#[CoversClass(RouteUrlGenerator::class)]
final class RouteUrlGeneratorTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function route(string $path, array $attributes = []): Route
    {
        return new Route([Method::GET], $path, 'Handler', 'r', $attributes);
    }

    #[Test]
    public function static_route_returns_relative_path(): void
    {
        self::assertSame('/about', RouteUrlGenerator::generate($this->route('/about'), [], null));
    }

    #[Test]
    public function substitutes_named_parameters(): void
    {
        self::assertSame(
            '/users/42/posts/7',
            RouteUrlGenerator::generate($this->route('/users/{id}/posts/{post}'), ['id' => '42', 'post' => '7'], null),
        );
    }

    #[Test]
    public function percent_encodes_parameters_to_prevent_path_traversal(): void
    {
        // RFC 3986 §2: rawurlencode percent-encodes the reserved '/' (→ %2F) so
        // a '../admin' value stays inside its single segment and cannot punch
        // out into a traversal. The dots are unreserved and remain literal —
        // harmless without a real '/' separator.
        $url = RouteUrlGenerator::generate($this->route('/files/{name}'), ['name' => '../admin'], null);

        self::assertSame('/files/..%2Fadmin', $url);
        self::assertStringNotContainsString('/../', $url);
        self::assertStringNotContainsString('/admin', $url); // slash encoded → no new segment
    }

    #[Test]
    public function strips_unfilled_optional_parameter(): void
    {
        // /blog/{page?} with no page → /blog (optional segment + its slash removed).
        self::assertSame('/blog', RouteUrlGenerator::generate($this->route('/blog/{page?}'), [], null));
    }

    #[Test]
    public function fills_optional_parameter_when_provided(): void
    {
        self::assertSame('/blog/2', RouteUrlGenerator::generate($this->route('/blog/{page?}'), ['page' => '2'], null));
    }

    #[Test]
    public function builds_subdomain_url_when_scope_maps_to_subdomain(): void
    {
        $domain = new DomainConfig(
            defaultDomain: 'example.com',
            subdomains: ['forum' => ['forum']],
            scheme: 'https',
        );

        $url = RouteUrlGenerator::generate(
            $this->route('/threads/{id}', ['scope' => 'forum']),
            ['id' => '1'],
            $domain,
        );

        self::assertSame('https://forum.example.com/threads/1', $url);
    }

    #[Test]
    public function falls_back_to_relative_path_without_matching_scope(): void
    {
        $domain = new DomainConfig(defaultDomain: 'example.com', subdomains: ['forum' => ['forum']]);

        // No 'scope' attribute → relative path even with a domain config.
        self::assertSame('/dashboard', RouteUrlGenerator::generate($this->route('/dashboard'), [], $domain));
    }
}
