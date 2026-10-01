<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\Cache\HttpCacheMiddleware;
use Pulsar\Http\Cache\InMemoryCacheStorage;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use ReflectionClass;

use function array_keys;
use function array_unique;
use function dirname;
use function file_get_contents;
use function implode;
use function is_string;
use function preg_match_all;
use function preg_split;
use function sort;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * The HTTP caching and error-path behaviour an operator has to know about.
 *
 * Three repairs changed what leaves the server — the shared response cache now
 * refuses anything carrying identity, `NoCacheMiddleware` refuses to run without
 * a dispatched route, and the out-of-pipeline error response now carries
 * protective headers — and none of them was written down. An operator upgrading
 * reads documentation, not diffs: undocumented, the first shows up as
 * unexplained cache misses, the second as a `LogicException` in production, and
 * the third as a CSP that appeared from nowhere.
 *
 * These tests do not grade the prose. Each one reads a fact out of the code and
 * fails when the documentation stops stating it, so a new refusal condition, a
 * new `cache.*` attribute or a new `X-Cache` value cannot ship undocumented.
 */
#[CoversNothing]
final class HttpCachingDocumentationTest extends TestCase
{
    /**
     * Every request header that sends a request past the shared store is named
     * in the caching guide.
     *
     * Read from the constant the middleware actually loops over, so adding a
     * fourth identity header without documenting it fails here.
     */
    #[Test]
    public function everyIdentityRequestHeaderIsListedInTheCachingGuide(): void
    {
        /** @var list<string> $headers */
        $headers = new ReflectionClass(HttpCacheMiddleware::class)
            ->getConstant('IDENTITY_REQUEST_HEADERS');

        $caching = $this->doc('caching.md');
        $missing = [];

        foreach ($headers as $header) {
            if (!str_contains($caching, $header)) {
                $missing[] = $header;
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/caching.md does not tell an operator that these request headers bypass the '
            . 'shared response cache: ' . implode(', ', $missing),
        );
    }

    /**
     * Every response-side refusal is named in the caching guide.
     *
     * The header names and the `Cache-Control` directives are extracted from the
     * body of `responseRefusesSharing()` rather than restated, because a refusal
     * the guide does not list is a cache miss an operator cannot explain.
     */
    #[Test]
    public function everyResponseSideRefusalConditionIsListedInTheCachingGuide(): void
    {
        $method = $this->methodBody('responseRefusesSharing');

        preg_match_all("/getHeaderLine\('([A-Za-z-]+)'\)/", $method, $headerMatches);
        preg_match_all("/str_contains\(\\\$cacheControl, '([a-z-]+)'\)/", $method, $directiveMatches);

        /** @var list<string> $conditions */
        $conditions = array_unique([...$headerMatches[1], ...$directiveMatches[1]]);
        sort($conditions);

        self::assertNotSame([], $conditions, 'No refusal condition could be read out of the middleware');

        $caching = $this->doc('caching.md');
        $missing = [];

        foreach ($conditions as $condition) {
            if (!str_contains($caching, $condition)) {
                $missing[] = $condition;
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/caching.md does not list these response-side refusals: ' . implode(', ', $missing),
        );
    }

    /**
     * Every `cache.*` request attribute the middleware reads is documented,
     * including the two whose meaning the repairs changed.
     */
    #[Test]
    public function everyCacheRequestAttributeIsDocumented(): void
    {
        preg_match_all("/getAttribute\('(cache\.[a-z_]+)'/", $this->middlewareSource(), $matches);

        /** @var list<string> $attributes */
        $attributes = array_unique($matches[1]);
        sort($attributes);

        self::assertNotSame([], $attributes, 'No cache.* attribute could be read out of the middleware');

        $caching = $this->doc('caching.md');
        $missing = [];

        foreach ($attributes as $attribute) {
            if (!str_contains($caching, $attribute)) {
                $missing[] = $attribute;
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/caching.md does not document these route cache attributes: ' . implode(', ', $missing),
        );
    }

    /**
     * Every value the middleware can put in `X-Cache` is documented.
     *
     * The header is the only thing an operator can read off the wire to tell a
     * refusal from a miss, and the two have to be told apart: a MISS is a cache
     * that will be warm next time, a BYPASS is a cache that never will be.
     */
    #[Test]
    public function everyXCacheValueIsDocumented(): void
    {
        $source = $this->middlewareSource();
        preg_match_all("/'X-Cache',\s*'([A-Z]+)'/", $source, $withHeader);
        preg_match_all("/'X-Cache'\]\s*=\s*'([A-Z]+)'/", $source, $arrayAssign);
        preg_match_all("/'X-Cache'\s*=>\s*'([A-Z]+)'/", $source, $arrayLiteral);

        /** @var list<string> $values */
        $values = array_unique([...$withHeader[1], ...$arrayAssign[1], ...$arrayLiteral[1]]);
        sort($values);

        self::assertNotSame([], $values, 'No X-Cache value could be read out of the middleware');

        $caching = $this->doc('caching.md');
        self::assertStringContainsString('X-Cache', $caching, 'docs/caching.md never names the X-Cache header');

        $missing = [];

        foreach ($values as $value) {
            if (!str_contains($caching, $value)) {
                $missing[] = $value;
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/caching.md does not document these X-Cache values: ' . implode(', ', $missing),
        );
    }

    /**
     * `cache.share_across_clients` is documented together with its limit.
     *
     * An opt-in that switches off an identity check is only safe to hand an
     * operator alongside the sentence saying what it does not check.
     */
    #[Test]
    public function theShareAcrossClientsOptInIsDocumentedWithItsLimit(): void
    {
        self::assertStringContainsString(
            "getAttribute('cache.share_across_clients')",
            $this->middlewareSource(),
            'This test exists to document an opt-in the middleware no longer reads',
        );

        $caching = $this->doc('caching.md');

        foreach (['cache.share_across_clients', 'Set-Cookie', 'does not inspect the body'] as $claim) {
            self::assertStringContainsString(
                $claim,
                $caching,
                'docs/caching.md documents the share_across_clients opt-in without stating its limit: '
                . 'missing ' . $claim,
            );
        }

        // The class documentation has to state the same limit. The response-side
        // refusals read headers; a personalised page that sets no cookie and
        // declares no Vary passes all of them. A docblock claiming the assertion
        // "cannot make a response that carries identity shareable" reads as a
        // guarantee to the next maintainer, and the operator guide would then be
        // the only place the actual bound is written down.
        self::assertStringContainsString(
            'does not inspect the body',
            $this->middlewareSource(),
            'HttpCacheMiddleware claims a stronger guarantee for cache.share_across_clients than '
            . 'docs/caching.md does; the response-side refusals are declaration-based',
        );
    }

    /**
     * Every identity header the opt-in actually waives says so in the table.
     *
     * `carriesIdentity()` returns early on `cache.share_across_clients` BEFORE
     * it reaches the header loop, so the opt-in waives the whole of
     * `IDENTITY_REQUEST_HEADERS` — not the subset a reader of the table would
     * guess. The table used to note the waiver on the `Authorization` and
     * `Proxy-Authorization` rows and leave the `Cookie` row silent, which reads
     * as "a cookie always refuses" on the page whose whole purpose is to make
     * cross-caller disclosure visible. The prose 60 lines further down said the
     * opposite and was right; a page that contradicts itself about which
     * requests reach a shared store is worse than a page that says nothing.
     *
     * The waiver is MEASURED rather than read out of the source: each header is
     * put on a real request with the opt-in set, and the middleware is asked
     * what it does with it. `BYPASS` means refused; anything else means the
     * store was used, which is the fact the row has to carry. Reading the early
     * return out of the file instead would pin the shape of today's
     * implementation rather than its behaviour, and would still be green if the
     * return moved below the loop.
     */
    #[Test]
    public function everyIdentityHeaderTheOptInWaivesIsMarkedAsWaivedInTheRefusalTable(): void
    {
        /** @var list<string> $headers */
        $headers = new ReflectionClass(HttpCacheMiddleware::class)
            ->getConstant('IDENTITY_REQUEST_HEADERS');

        self::assertNotSame([], $headers, 'The middleware loops over no identity header at all');

        $caching = $this->doc('caching.md');
        $unmarked = [];
        $waived = [];

        foreach ($headers as $header) {
            $middleware = new HttpCacheMiddleware(new InMemoryCacheStorage(), defaultTtl: 60);

            $request = new ServerRequest(method: 'GET', uri: '/pricing')
                ->withHeader($header, 'value-that-identifies-one-caller')
                ->withAttribute('cache.share_across_clients', true);

            $handler = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(statusCode: 200, body: 'Public page');
                }
            };

            if ($middleware->process($request, $handler)->getHeaderLine('X-Cache') === 'BYPASS') {
                continue;
            }

            $waived[] = $header;

            $row = $this->refusalTableRow($caching, $header);

            if (!str_contains($row, 'cache.share_across_clients')) {
                $unmarked[] = $header;
            }
        }

        self::assertNotSame(
            [],
            $waived,
            'cache.share_across_clients waives no identity header any more, so this test guards nothing',
        );

        self::assertSame(
            [],
            $unmarked,
            'docs/caching.md refuses these headers in its request table without saying that '
            . 'cache.share_across_clients waives them, so the table understates a cross-caller '
            . 'disclosure risk: ' . implode(', ', $unmarked),
        );
    }

    /**
     * The one row of the request-refusal table that names a header.
     *
     * Matched on the backticked name so `Authorization` does not select the
     * `Proxy-Authorization` row, and the whole row is returned so the assertion
     * reads the Notes cell rather than the page.
     */
    private function refusalTableRow(string $caching, string $header): string
    {
        $needle = '`' . $header . '` request header';

        foreach (preg_split('/\R/', $caching) ?: [] as $line) {
            if (str_starts_with(trim($line), '|') && str_contains($line, $needle)) {
                return $line;
            }
        }

        self::fail('docs/caching.md has no request-refusal table row for the ' . $header . ' header');
    }

    /**
     * The wiring `NoCacheMiddleware` now demands is documented, with both
     * supported positions.
     *
     * It throws where it cannot see a dispatched route, so an operator who wired
     * it globally learns about it from a production stack trace unless the page
     * that documents it says which two wirings work.
     */
    #[Test]
    public function bothSupportedNoCacheWiringsAreDocumented(): void
    {
        self::assertStringContainsString(
            'throw new LogicException',
            $this->source('src/Http/Middleware/NoCacheMiddleware.php'),
            'This test exists to document a refusal NoCacheMiddleware no longer makes',
        );

        $http = $this->doc('http.md');

        foreach (["middleware: ['no-cache']", 'PostRoutingPipeline', 'LogicException'] as $claim) {
            self::assertStringContainsString(
                $claim,
                $http,
                'docs/http.md does not document the NoCacheMiddleware wiring requirement: missing ' . $claim,
            );
        }
    }

    /**
     * The wiring the guidance tells an operator to use has to exist.
     *
     * Route middleware is attached through the `Route` constructor's
     * `middleware:` argument. There is no fluent `Router::middleware()`, and
     * never has been — so every `->middleware([...])` in the guidance was an
     * instruction that fails with `Error: Call to undefined method`. The
     * `NoCacheMiddleware` repair inherited it into a `LogicException` message,
     * which is the worst place for it: the operator reads that message precisely
     * when their wiring is already wrong.
     */
    #[Test]
    public function theRouteMiddlewareWiringGuidanceNamesAnApiThatExists(): void
    {
        $constructor = new ReflectionClass(Route::class)->getConstructor();
        self::assertNotNull($constructor, 'Route has no constructor to attach middleware through');

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = $parameter->getName();
        }

        self::assertContains('middleware', $parameters, 'Route::__construct() no longer takes middleware');
        self::assertFalse(
            new ReflectionClass(Router::class)->hasMethod('middleware'),
            'Router now has a middleware() method, so the fluent guidance this test forbids is valid again',
        );

        $offenders = [];
        $guidance = [
            'src/Http/Middleware/NoCacheMiddleware.php',
            'src/Http/Attribute/NoCacheResponse.php',
            'src/Http/Middleware/MiddlewareAliasConfig.php',
            'docs/http.md',
            'docs/middleware.md',
            'docs/caching.md',
        ];

        foreach ($guidance as $relativePath) {
            if (str_contains($this->source($relativePath), '->middleware(')) {
                $offenders[] = $relativePath;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These files tell the reader to call a Router::middleware() that does not exist: '
            . implode(', ', $offenders),
        );
    }

    /**
     * The non-destructive `StreamedResponse` body is documented.
     *
     * Reading the body used to empty the response. Middleware authors were
     * entitled to know it did; they are entitled to know it no longer does.
     */
    #[Test]
    public function theStreamedResponseBodyIsDocumentedAsRepeatable(): void
    {
        self::assertStringContainsString(
            'StreamedBodyBuffer',
            $this->source('src/Http/Response/StreamedResponse.php'),
            'This test exists to document a buffer StreamedResponse no longer keeps',
        );

        $http = $this->doc('http.md');

        foreach (['StreamedResponse', 'getBody()', 'getSource()'] as $claim) {
            self::assertStringContainsString(
                $claim,
                $http,
                'docs/http.md does not document StreamedResponse body materialization: missing ' . $claim,
            );
        }
    }

    /**
     * Every header the kernel now adds to an out-of-pipeline error response is
     * documented, read from the single definition the kernel reads.
     */
    #[Test]
    public function everyLastResortErrorHeaderIsDocumented(): void
    {
        $http = $this->doc('http.md');
        $missing = [];

        foreach (array_keys(ProductionRenderer::LAST_RESORT_HEADERS) as $header) {
            if (!str_contains($http, $header)) {
                $missing[] = $header;
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/http.md does not document the headers the error path now carries: ' . implode(', ', $missing),
        );
    }

    /**
     * The caching change is in the upgrade guide as a behaviour change.
     *
     * A deployment that relied on the old sharing gets cache misses it did not
     * get before. That belongs on the page an operator reads before deploying,
     * not only on the page they read when something already broke.
     */
    #[Test]
    public function theCachingBehaviourChangeIsInTheUpgradeGuide(): void
    {
        $upgrade = $this->doc('upgrade.md');

        foreach (['HttpCacheMiddleware', 'cache misses', 'NoCacheMiddleware'] as $claim) {
            self::assertStringContainsString(
                $claim,
                $upgrade,
                'docs/upgrade.md does not record the HTTP caching behaviour change: missing ' . $claim,
            );
        }
    }

    /**
     * The body of one private method of the cache middleware, so a refusal
     * condition is read where it is enforced rather than from a comment.
     */
    private function methodBody(string $name): string
    {
        $source = $this->middlewareSource();
        $start = strpos($source, 'private function ' . $name);
        self::assertIsInt($start, 'HttpCacheMiddleware::' . $name . '() is gone');

        $next = strpos($source, 'private function ', $start + 1);

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }

    private function middlewareSource(): string
    {
        return $this->source('src/Http/Cache/HttpCacheMiddleware.php');
    }

    private function source(string $relativePath): string
    {
        return $this->read(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $relativePath);
    }

    private function doc(string $name): string
    {
        return $this->read(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $name);
    }

    private function read(string $path): string
    {
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertTrue(is_string($contents));

        return $contents;
    }
}
