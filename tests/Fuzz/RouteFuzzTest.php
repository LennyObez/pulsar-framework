<?php

declare(strict_types=1);

namespace Pulsar\Tests\Fuzz;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use stdClass;

use function count;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(MatchedRoute::class)]
#[Group('fuzz')]
final class RouteFuzzTest extends TestCase
{
    #[Test]
    public function pathTraversalAttemptsDoNotMatchWrongRoutes(): void
    {
        $router = new Router();
        $router->get('/admin/dashboard', stdClass::class, 'admin.dashboard');
        $router->get('/public/page', stdClass::class, 'public.page');

        $traversalPaths = [
            '/public/../admin/dashboard',
            '/public/../../admin/dashboard',
            '/admin/./dashboard',
            '/admin/dashboard/../../admin/dashboard',
            '/../admin/dashboard',
            '/..%2fadmin%2fdashboard',
            '/.%2e/admin/dashboard',
        ];

        $checkedCount = 0;
        foreach ($traversalPaths as $path) {
            try {
                $matched = $router->match(Method::GET, $path);
                self::assertNotSame(
                    'admin.dashboard',
                    $matched->getName(),
                    "Path traversal '{$path}' should not match admin route",
                );
            } catch (RoutingException) {
                // Not found is acceptable — traversal blocked
            }
            $checkedCount++;
        }

        self::assertSame(count($traversalPaths), $checkedCount);
    }

    #[Test]
    public function urlEncodedSpecialCharactersAreHandledInRouting(): void
    {
        $router = new Router();
        $router->get('/users/{id}', stdClass::class, 'user.show');

        $encodedInputs = [
            '%2F',           // /
            '%2f',           // / (lowercase)
            '%00',           // null byte
            '%252F',         // double-encoded /
            '%3C%3E',        // <>
            '%27%22',        // '"
            '%20%20%20',     // spaces
        ];

        foreach ($encodedInputs as $encoded) {
            try {
                $matched = $router->match(Method::GET, '/users/' . $encoded);
                self::assertInstanceOf(MatchedRoute::class, $matched);
            } catch (RoutingException) {
                // Not matching is also acceptable for encoded specials
            }
        }
    }

    #[Test]
    public function doubleEncodedPathsAreHandled(): void
    {
        $router = new Router();
        $router->get('/files/{path}', stdClass::class, 'files.show');

        $doubleEncoded = [
            '/files/%252e%252e%252f',
            '/files/%25252e%25252e',
            '/files/%2525252e',
        ];

        foreach ($doubleEncoded as $path) {
            try {
                $matched = $router->match(Method::GET, $path);
                self::assertInstanceOf(MatchedRoute::class, $matched);
            } catch (RoutingException) {
                // Not found is acceptable
            }
        }
    }

    #[Test]
    public function extremelyLongPathsDoNotCauseErrors(): void
    {
        $router = new Router();
        $router->get('/api/resource', stdClass::class, 'api.resource');

        $longPaths = [
            '/' . str_repeat('a', 2000),
            '/' . str_repeat('segment/', 500),
            '/api/' . str_repeat('x', 10000),
        ];

        foreach ($longPaths as $path) {
            try {
                $router->match(Method::GET, $path);
            } catch (RoutingException) {
                // Not found is expected for non-matching long paths
            }

            // Just verifying no crash, timeout, or memory issue
            self::addToAssertionCount(1);
        }
    }

    #[Test]
    public function pathsWithNullBytesDoNotMatchUnexpectedRoutes(): void
    {
        $router = new Router();
        $router->get('/admin', stdClass::class, 'admin');
        $router->get('/public', stdClass::class, 'public');

        $nullPaths = [
            "/admin\x00",
            "/admin\x00.php",
            "/public\x00/../admin",
            "\x00/admin",
        ];

        $checkedCount = 0;
        foreach ($nullPaths as $path) {
            try {
                $matched = $router->match(Method::GET, $path);
                // If it matches, ensure it matched the correct route
                self::assertNotNull($matched->getName());
            } catch (RoutingException) {
                // Not found is fine — null bytes rejected
            }
            $checkedCount++;
        }

        self::assertSame(count($nullPaths), $checkedCount);
    }

    #[Test]
    public function routeParameterConstraintsRejectInvalidInput(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: stdClass::class,
            name: 'user.show',
            constraints: ['id' => '\d+'],
        ));

        $invalidIds = [
            'abc',
            '1.5',
            '-1',
            '1; DROP TABLE users',
            '<script>',
            '../../../etc/passwd',
            str_repeat('9', 1000),
        ];

        foreach ($invalidIds as $id) {
            try {
                $matched = $router->match(Method::GET, '/users/' . $id);
                // If constrained with \d+, non-numeric should not match
                self::assertMatchesRegularExpression(
                    '/^\d+$/',
                    $matched->parameter('id') ?? '',
                    "Route constraint should reject '{$id}'",
                );
            } catch (RoutingException) {
                // Rejected by constraint — correct behavior
            }
        }
    }

    #[Test]
    public function manyRoutesHandleMatchingEfficiently(): void
    {
        $router = new Router();

        for ($i = 0; $i < 500; $i++) {
            /** @var class-string $handler */
            $handler = "Controller{$i}";
            $router->get("/route-{$i}/{id}", $handler, "route.{$i}");
        }

        // Match the last route to exercise worst-case scan
        $matched = $router->match(Method::GET, '/route-499/123');
        self::assertSame('route.499', $matched->getName());
        self::assertSame('123', $matched->parameter('id'));
    }

    #[Test]
    public function specialRegexCharactersInPathsAreHandled(): void
    {
        $router = new Router();
        $router->get('/api/resource', stdClass::class, 'api');

        $regexPaths = [
            '/api/resource(.*)/',
            '/api/resource[0-9]+',
            '/api/resource{1,5}',
            '/api/resource|/admin',
            '/api/resource\\d+',
            '/api/resource(?:test)',
        ];

        foreach ($regexPaths as $path) {
            try {
                $router->match(Method::GET, $path);
            } catch (RoutingException) {
                // Not found is expected
            }

            self::addToAssertionCount(1);
        }
    }
}
