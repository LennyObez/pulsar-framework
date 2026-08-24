<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * What a binding refusal is allowed to say, and what it must carry when it says
 * it.
 *
 * The bodies used to be the exception message. Every factory on
 * {@see ModelBindingException} names the model class, and three of them append
 * the route parameter's raw value, so a `404` read
 * `No [App\Domain\Patient] found for [id] = "4181"` — the internal class layout
 * of the application handed to an unauthenticated scanner, next to the segment
 * the scanner itself had just sent, reflected back out of a response that
 * declared no content type at all. Two disclosures and a sniffing surface, on
 * the one code path a caller can trigger at will by guessing ids.
 *
 * So the body is the reason phrase and nothing else, and the diagnosis goes to
 * the log, where the operator who can act on a misdeclared route will see the
 * message that names the parameters and the three ways to fix it.
 *
 * The headers are the second half. These responses are produced inside the
 * post-routing pipeline, and what wraps them from the outside is configuration:
 * `SecurityHeadersMiddleware` is wired when an application asks for it, sets
 * what it is configured to set, and never sets `Content-Type` or
 * `Cache-Control` at all. A refusal that depends on who is asking must not be
 * stored by a shared cache keyed on the URL alone, and a body served without a
 * type is a body the browser is free to sniff — so the refusal carries the
 * floor itself, the same set {@see \Pulsar\ErrorHandling\ProductionRenderer}
 * gives an error page built where no pipeline will decorate it.
 */
#[CoversClass(ModelBindingMiddleware::class)]
final class BindingRefusalResponseTest extends TestCase
{
    // ── What the body may say ────────────────────────────────────────────

    #[Test]
    public function theNotFoundBodyIsTheReasonPhraseAndNothingElse(): void
    {
        $text = $this->dispatch($this->middleware(new RefusalResolver(found: false)), $this->request());

        self::assertSame(404, $text->getStatusCode());
        self::assertSame('Not Found', (string) $text->getBody());

        $json = $this->dispatch($this->middleware(new RefusalResolver(found: false)), $this->request(json: true));

        self::assertSame(404, $json->getStatusCode());
        self::assertSame(
            ['error' => 'Not Found', 'status' => 404],
            json_decode((string) $json->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function aRefusalNamesNeitherTheModelClassNorTheSegmentItWasGiven(): void
    {
        // The two disclosures, asserted separately from the exact wording so
        // that rephrasing the body cannot quietly reintroduce either.
        foreach ([new RefusalResolver(found: false), new RefusalResolver(keyNameRefused: true)] as $resolver) {
            foreach ([true, false] as $json) {
                $body = (string) $this->dispatch($this->middleware($resolver), $this->request(json: $json))
                    ->getBody();

                self::assertStringNotContainsString(RefusalRecord::class, $body);
                self::assertStringNotContainsString('RefusalRecord', $body);
                self::assertStringNotContainsString(self::RECORD_ID, $body);
                self::assertStringNotContainsString('secret_column', $body);
            }
        }
    }

    #[Test]
    public function theBadRequestBodyRepeatsNothingTheResolverPutInItsMessage(): void
    {
        // A 400 comes from the persistence adapter — a key name outside the
        // allow-list — and its message names the column the caller asked to be
        // looked up by. That is application schema, and the caller supplied
        // half of it.
        $response = $this->dispatch($this->middleware(new RefusalResolver(keyNameRefused: true)), $this->request());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Bad Request', (string) $response->getBody());
    }

    #[Test]
    public function theUnscopableRouteStillAnswersWithTheFixedFiveHundred(): void
    {
        $response = $this->dispatch($this->middleware(new RefusalResolver()), $this->comparisonRequest());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
    }

    // ── Where the diagnosis goes instead ─────────────────────────────────

    #[Test]
    public function theRouteMisdeclarationReachesTheLogAtErrorLevel(): void
    {
        // The refusal a scoping check writes names the parameters and the three
        // ways to fix the route. It was written, converted to a 500, and
        // dropped — the route was broken for every request and left no trail.
        //
        // It is announced by BindingResolver rather than by the middleware, and
        // announced ONCE: the refusal is decided from declarations and memoised,
        // so repeating it per request was the same permanent fact reprinted
        // forever on a path an unauthenticated caller can reach. The
        // per-request line is a `debug` counterpart; the count lives on
        // `pulsar_model_binding_refusals_total`. See AnonymousDenialAuditTest.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('names no relation'),
                self::callback(static fn(array $context): bool => $context['status'] === 500),
            );

        $middleware = $this->middleware(new RefusalResolver(), $logger);

        for ($i = 0; $i < 25; $i++) {
            self::assertSame(500, $this->dispatch($middleware, $this->comparisonRequest())->getStatusCode());
        }
    }

    #[Test]
    public function theClientErrorReachesTheLogAtDebugLevel(): void
    {
        // A 404 is ordinary traffic: an id scan must not be able to fill a disk
        // through this line, so it is debug rather than warning — but the
        // message that no longer reaches the client does reach the operator.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::stringContains(RefusalRecord::class),
                self::callback(static fn(array $context): bool => $context['status'] === 404),
            );
        $logger->expects(self::never())->method('error');

        $this->dispatch($this->middleware(new RefusalResolver(found: false), $logger), $this->request());
    }

    // ── What every refusal carries ───────────────────────────────────────

    /**
     * @param callable(BindingRefusalResponseTest, bool): ResponseInterface $refusal
     */
    #[Test]
    #[DataProvider('refusalProvider')]
    public function everyRefusalCarriesTheHeadersOfAResponseThatMayNotBeCachedOrSniffed(
        int $expectedStatus,
        callable $refusal,
    ): void {
        foreach ([true, false] as $json) {
            $response = $refusal($this, $json);

            self::assertSame($expectedStatus, $response->getStatusCode());
            self::assertSame(
                $json ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8',
                $response->getHeaderLine('Content-Type'),
            );
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame('Accept', $response->getHeaderLine('Vary'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
            self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        }
    }

    /**
     * @return iterable<string, array{int, callable(BindingRefusalResponseTest, bool): ResponseInterface}>
     */
    public static function refusalProvider(): iterable
    {
        yield '401 no identity' => [401, static fn(self $test, bool $json): ResponseInterface => $test->dispatch($test->middleware(new RefusalResolver(), anonymous: true), $test->request(json: $json))];

        // The hook's denial and an absent row are the same answer on purpose:
        // a status that differed would tell a caller the policy refuses whether
        // the record exists. Both cases are listed, and they are listed under
        // the same status.
        yield '404 hook denial' => [404, static fn(self $test, bool $json): ResponseInterface => $test->dispatch($test->middleware(new RefusalResolver(), hookAllows: false), $test->request(json: $json))];

        yield '403 illegal opt-out' => [403, static fn(self $test, bool $json): ResponseInterface => $test->dispatch($test->middleware(new RefusalResolver()), $test->request(json: $json, attributes: ['_without_authorization' => true]))];

        yield '404 model absent' => [404, static fn(self $test, bool $json): ResponseInterface => $test->dispatch($test->middleware(new RefusalResolver(found: false)), $test->request(json: $json))];

        yield '400 key name refused' => [400, static fn(self $test, bool $json): ResponseInterface => $test->dispatch($test->middleware(new RefusalResolver(keyNameRefused: true)), $test->request(json: $json))];

        yield '500 unscopable route' => [500, static fn(self $test, bool $json): ResponseInterface => $test->dispatch($test->middleware(new RefusalResolver()), $test->comparisonRequest($json))];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private const string RECORD_ID = '4181';

    public function middleware(
        RefusalResolver $resolver,
        ?LoggerInterface $logger = null,
        bool $anonymous = false,
        bool $hookAllows = true,
    ): ModelBindingMiddleware {
        $identity = $anonymous ? null : $this->caller();

        $hook = $this->createStub(AuthorizationHookInterface::class);
        $hook->method('authorize')->willReturn($hookAllows);

        return new ModelBindingMiddleware(
            binder: new ModelBinder(
                defaultResolver: $resolver,
                // The logger reaches the resolver, which is where a route
                // misdeclaration is DECIDED and therefore where it is announced.
                bindingResolver: new BindingResolver(logger: $logger),
                container: $this->createStub(ContainerInterface::class),
            ),
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            authHook: $hook,
            identityResolver: static fn(): ?IdentityInterface => $identity,
            logger: $logger,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function request(bool $json = false, array $attributes = []): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/refusals/{record}',
            handler: [RefusalController::class, 'show'],
            name: 'refusals.show',
            attributes: $attributes,
        );

        return new ServerRequest('GET', '/refusals/' . self::RECORD_ID, $json ? ['Accept' => 'application/json'] : [])
            ->withAttribute('_route', new MatchedRoute($route, ['record' => self::RECORD_ID]));
    }

    /**
     * A route whose second placeholder sits straight behind the first, so no
     * segment names a relation to resolve it through: the containment cannot be
     * checked and the route is refused for every caller.
     */
    public function comparisonRequest(bool $json = false): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/compare/{left}/{right}',
            handler: [RefusalController::class, 'compare'],
            name: 'refusals.compare',
        );

        return new ServerRequest('GET', '/compare/1/2', $json ? ['Accept' => 'application/json'] : [])
            ->withAttribute('_route', new MatchedRoute($route, ['left' => '1', 'right' => '2']));
    }

    /**
     * Run the middleware the way the kernel runs it: bound to the route being
     * dispatched, which the pipeline hands it rather than the request.
     *
     * The request builders here write the same route into both places, which is
     * what a real dispatch does. That the two cannot be made to DISAGREE is the
     * subject of {@see DispatchedRouteAuthorityTest}.
     */
    public function dispatch(ModelBindingMiddleware $middleware, ServerRequestInterface $request): ResponseInterface
    {
        /** @var MatchedRoute|null $route */
        $route = $request->getAttribute('_route');

        $bound = $route === null ? $middleware : $middleware->forDispatchedRoute($route);

        return $bound->process($request, $this->handler());
    }

    public function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'handler reached'));

        return $handler;
    }

    private function caller(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('caller-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }
}

/**
 * A resolver that can miss, or refuse the key name the way a persistence
 * adapter does.
 *
 * @internal
 */
final readonly class RefusalResolver implements ModelResolverPort
{
    public function __construct(
        private bool $found = true,
        private bool $keyNameRefused = false,
    ) {}

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        if ($this->keyNameRefused) {
            throw ModelBindingException::invalidKeyName('secret_column', $modelClass);
        }

        return $this->found ? new RefusalRecord() : null;
    }

    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): ?object {
        return $this->found ? new RefusalRecord() : null;
    }
}

/** @internal */
final class RefusalRecord {}

/** @internal */
final class RefusalController
{
    public function show(RefusalRecord $record): void {}

    public function compare(RefusalRecord $left, RefusalRecord $right): void {}
}
