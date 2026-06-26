<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\CallableRequestHandler;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;

use function array_reverse;
use function explode;

/**
 * End-to-end test of the session-backed CSRF flow through SessionMiddleware +
 * CsrfMiddleware: a token rendered on a GET must validate on the subsequent
 * POST. This only works if SessionMiddleware emits the session cookie on the
 * GET so the POST carries the same session id back and the server-stored token
 * is reachable.
 *
 * The two requests share one ArrayHandler (the persistent store) but use
 * separate SessionManager/CsrfTokenManager instances, mirroring two real
 * requests against the same backing store.
 */
#[CoversClass(SessionMiddleware::class)]
#[CoversClass(SessionManager::class)]
#[CoversClass(CsrfMiddleware::class)]
final class SessionCsrfCookieFlowTest extends TestCase
{
    private function sessionConfig(): SessionConfig
    {
        return new SessionConfig(
            cookieName: 'PULSAR_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Lax',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );
    }

    private function csrfConfig(): CsrfConfig
    {
        // trustedOrigins empty => the Origin/Referer layer is skipped; the
        // synchronizer-token layer (the one under test) always applies.
        return new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );
    }

    /**
     * Run one request through SessionMiddleware -> CsrfMiddleware -> controller,
     * with a fresh SessionManager/CsrfTokenManager bound to the shared store.
     *
     * @param callable(ServerRequestInterface, CsrfTokenManager): ResponseInterface $controller
     */
    private function handleRequest(ArrayHandler $store, ServerRequestInterface $request, callable $controller): ResponseInterface
    {
        $sessionManager = new SessionManager($store, $this->sessionConfig());
        $csrf = new CsrfTokenManager($sessionManager, $this->csrfConfig());

        $sessionMiddleware = new SessionMiddleware($sessionManager, new FlashBag($sessionManager));
        $csrfMiddleware = new CsrfMiddleware($csrf, $this->csrfConfig());

        $controllerHandler = new CallableRequestHandler(
            static fn(ServerRequestInterface $r): ResponseInterface => $controller($r, $csrf),
        );

        return $this->dispatch([$sessionMiddleware, $csrfMiddleware], $request, $controllerHandler);
    }

    /**
     * Compose middlewares around a final handler and run the request.
     *
     * @param list<MiddlewareInterface> $middlewares Outermost first.
     */
    private function dispatch(array $middlewares, ServerRequestInterface $request, RequestHandlerInterface $final): ResponseInterface
    {
        $handler = $final;

        foreach (array_reverse($middlewares) as $middleware) {
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>|null $parsedBody
     */
    private function request(string $method, string $path, array $cookies = [], ?array $parsedBody = null): ServerRequest
    {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: ['User-Agent' => 'PHPUnit'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: $cookies,
            parsedBody: $parsedBody,
        );
    }

    /**
     * @return array{0: string, 1: string} [name, value]
     */
    private function parseSetCookie(string $header): array
    {
        $first = explode(';', $header)[0];
        $pair = explode('=', $first, 2);

        return [$pair[0], $pair[1] ?? ''];
    }

    /**
     * GET renders a token; POST with the returned cookie + token passes CSRF.
     * Also asserts the GET actually emits the session cookie (the bug).
     */
    #[Test]
    public function token_rendered_on_get_validates_on_subsequent_post(): void
    {
        $store = new ArrayHandler();
        $token = '';

        $getResponse = $this->handleRequest(
            $store,
            $this->request('GET', '/contact'),
            static function (ServerRequestInterface $r, CsrfTokenManager $csrf) use (&$token): ResponseInterface {
                $token = $csrf->getToken();

                return Response::html('<form><input name="_csrf_token" value="' . $token . '"></form>');
            },
        );

        self::assertSame(200, $getResponse->getStatusCode());

        $setCookie = $getResponse->getHeader('Set-Cookie');
        self::assertNotEmpty($setCookie, 'the GET response must carry a Set-Cookie for the session id');

        [$cookieName, $cookieValue] = $this->parseSetCookie($setCookie[0]);
        self::assertSame('PULSAR_SESSION', $cookieName);
        self::assertNotSame('', $cookieValue);
        self::assertNotSame('', $token);

        $postResponse = $this->handleRequest(
            $store,
            $this->request('POST', '/contact', [$cookieName => $cookieValue], ['_csrf_token' => $token]),
            static fn(ServerRequestInterface $r, CsrfTokenManager $csrf): ResponseInterface => Response::html('OK'),
        );

        self::assertSame(200, $postResponse->getStatusCode(), 'POST with the session cookie + rendered token must pass CSRF');
        self::assertSame('OK', (string) $postResponse->getBody());
    }

    #[Test]
    public function post_without_a_token_is_forbidden(): void
    {
        $store = new ArrayHandler();

        $response = $this->handleRequest(
            $store,
            $this->request('POST', '/contact'),
            static fn(ServerRequestInterface $r, CsrfTokenManager $csrf): ResponseInterface => Response::html('OK'),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('missing', (string) $response->getBody());
    }

    #[Test]
    public function post_with_token_but_no_session_cookie_is_forbidden(): void
    {
        // First obtain a real token from a GET (stored under that GET's session id).
        $store = new ArrayHandler();
        $token = '';

        $this->handleRequest(
            $store,
            $this->request('GET', '/contact'),
            static function (ServerRequestInterface $r, CsrfTokenManager $csrf) use (&$token): ResponseInterface {
                $token = $csrf->getToken();

                return Response::html('ok');
            },
        );

        self::assertNotSame('', $token);

        // POST the token WITHOUT the session cookie: the server starts a brand-new
        // (empty) session, so the stored token is unreachable and validation fails.
        $response = $this->handleRequest(
            $store,
            $this->request('POST', '/contact', [], ['_csrf_token' => $token]),
            static fn(ServerRequestInterface $r, CsrfTokenManager $csrf): ResponseInterface => Response::html('OK'),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('invalid', (string) $response->getBody());
    }
}
