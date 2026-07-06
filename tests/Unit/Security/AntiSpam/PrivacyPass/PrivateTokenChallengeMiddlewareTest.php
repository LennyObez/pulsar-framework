<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassChallengeIssuer;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivateTokenChallengeMiddleware;

use function array_filter;
use function str_starts_with;

#[CoversClass(PrivateTokenChallengeMiddleware::class)]
final class PrivateTokenChallengeMiddlewareTest extends TestCase
{
    #[Test]
    public function addsTheChallengeHeaderOnAForbiddenResponse(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handlerReturning(403));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringStartsWith('PrivateToken ', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function addsTheChallengeHeaderOnAnUnauthorizedResponse(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handlerReturning(401));

        self::assertStringStartsWith('PrivateToken ', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function doesNotTouchSuccessfulResponses(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handlerReturning(200));

        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function preservesExistingAuthenticateChallenges(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('', 401)->withHeader('WWW-Authenticate', 'Bearer realm="api"');
            }
        };

        $values = $this->middleware()->process($this->request(), $handler)->getHeader('WWW-Authenticate');

        self::assertContains('Bearer realm="api"', $values);
        self::assertTrue((bool) array_filter($values, static fn(string $v): bool => str_starts_with($v, 'PrivateToken ')));
    }

    private function middleware(): PrivateTokenChallengeMiddleware
    {
        $issuer = new PrivacyPassChallengeIssuer(Rfc9578TestVector::challenge(), Rfc9578TestVector::spkiDer());

        return new PrivateTokenChallengeMiddleware($issuer);
    }

    private function request(): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/');
    }

    private function handlerReturning(int $status): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public function __construct(private readonly int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('body', $this->status);
            }
        };
    }
}
