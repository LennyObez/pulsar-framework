<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Csrf\CsrfBindingContext;
use Pulsar\Security\Csrf\CsrfBindingCookieMiddleware;

use function str_repeat;
use function strlen;

#[CoversClass(CsrfBindingCookieMiddleware::class)]
#[CoversClass(CsrfBindingContext::class)]
final class CsrfBindingCookieMiddlewareTest extends TestCase
{
    private CsrfBindingContext $context;
    private CsrfBindingCookieMiddleware $middleware;

    protected function setUp(): void
    {
        $this->context = new CsrfBindingContext();
        $this->middleware = new CsrfBindingCookieMiddleware($this->context);
    }

    /**
     * A handler that records the binding the middleware published, so a test can
     * assert what the token manager would have seen mid-request.
     *
     * @return RequestHandlerInterface&object{binding: string}
     */
    private function bindingRecorder(): RequestHandlerInterface
    {
        return new class ($this->context) implements RequestHandlerInterface {
            public string $binding = '';

            public function __construct(private readonly CsrfBindingContext $context) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->binding = $this->context->current();

                return Response::text('OK');
            }
        };
    }

    #[Test]
    public function mintsAHostPrefixedSecureCookieWhenAbsentAndPublishesTheBinding(): void
    {
        $recorder = $this->bindingRecorder();
        $request = new ServerRequest(method: 'GET', uri: '/');

        $response = $this->middleware->process($request, $recorder);

        // The binding is visible to the handler (and thus the token manager).
        self::assertSame(64, strlen($recorder->binding), '32 random bytes as hex');

        // The cookie is emitted with the full __Host- hardening.
        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('__Host-pulsar-csrf=' . $recorder->binding, $setCookie);
        self::assertStringContainsString('Path=/', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Strict', $setCookie);
    }

    #[Test]
    public function reusesAWellFormedExistingCookieWithoutResettingIt(): void
    {
        $existing = str_repeat('ab', 32); // 64 hex chars
        $recorder = $this->bindingRecorder();
        $request = new ServerRequest(method: 'GET', uri: '/')
            ->withCookieParams(['__Host-pulsar-csrf' => $existing]);

        $response = $this->middleware->process($request, $recorder);

        self::assertSame($existing, $recorder->binding, 'the existing binding is published unchanged');
        self::assertSame('', $response->getHeaderLine('Set-Cookie'), 'a valid cookie is not needlessly reset');
    }

    #[Test]
    public function remintsWhenTheExistingCookieIsMalformed(): void
    {
        $recorder = $this->bindingRecorder();
        $request = new ServerRequest(method: 'GET', uri: '/')
            ->withCookieParams(['__Host-pulsar-csrf' => 'not-hex-or-wrong-length']);

        $response = $this->middleware->process($request, $recorder);

        self::assertSame(64, strlen($recorder->binding));
        self::assertNotSame('not-hex-or-wrong-length', $recorder->binding);
        self::assertStringContainsString('__Host-pulsar-csrf=', $response->getHeaderLine('Set-Cookie'));
    }
}
