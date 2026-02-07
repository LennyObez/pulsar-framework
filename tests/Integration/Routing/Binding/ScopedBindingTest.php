<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

#[CoversClass(ModelBinder::class)]
#[CoversClass(ModelBindingMiddleware::class)]
final class ScopedBindingTest extends TestCase
{
    #[Test]
    public function parentChildResolutionUsesResolveScoped(): void
    {
        $parent = new stdClass();
        $parent->id = 1;
        $child = new stdClass();
        $child->id = 5;

        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($parent);
        $resolver->expects(self::once())
            ->method('resolveScoped')
            ->with(
                stdClass::class,
                'id',
                self::anything(),
                $parent,
                'posts',
                self::isInstanceOf(ResolutionContext::class),
            )
            ->willReturn($child);

        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    scoped: true,
                    parentRelation: 'posts',
                ),
            ],
        ]);

        $bindingResolver = new BindingResolver(compiledMap: $compiledMap);
        $binder = new ModelBinder(
            $resolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
        );

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')->willReturn(true);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('user-1');

        $config = new ModelBindingConfig(preset: 'standard');
        $middleware = new ModelBindingMiddleware(
            $binder,
            $config,
            $authHook,
            identityResolver: static fn() => $identity,
        );

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [ScopedTestController::class, 'show'],
            'posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '5']);

        $request = new ServerRequest('GET', '/users/1/posts/5');
        $request = $request->withAttribute('_route', $matched);

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static function ($req) use (&$capturedRequest): bool {
                $capturedRequest = $req;
                return true;
            }))
            ->willReturn(new Response(statusCode: 200, body: 'ok'));

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertSame($parent, $capturedRequest->getAttribute('_model_user'));
        self::assertSame($child, $capturedRequest->getAttribute('_model_post'));
    }

    #[Test]
    public function missingScopedModelReturns404(): void
    {
        $parent = new stdClass();

        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturn($parent);
        $resolver->method('resolveScoped')->willReturn(null);

        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    scoped: true,
                    parentRelation: 'posts',
                ),
            ],
        ]);

        $bindingResolver = new BindingResolver(compiledMap: $compiledMap);
        $binder = new ModelBinder(
            $resolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
        );

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig(preset: 'standard');
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [ScopedTestController::class, 'show'],
            'posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '999']);

        $request = new ServerRequest('GET', '/users/1/posts/999');
        $request = $request->withAttribute('_route', $matched);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }
}

final class ScopedTestController
{
    public function show(stdClass $user, stdClass $post): void {}
}
