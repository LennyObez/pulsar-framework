<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Container\Container;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Http\Middleware\PostRoutingPipeline;

#[CoversClass(PostRoutingPipeline::class)]
final class PostRoutingPipelineTest extends TestCase
{
    #[Test]
    public function aFreshPipelineIsEmpty(): void
    {
        self::assertTrue(new PostRoutingPipeline()->isEmpty());
    }

    #[Test]
    public function pipingMakesItNonEmpty(): void
    {
        $pipeline = new PostRoutingPipeline();
        $pipeline->pipe(new PostRoutingTagMiddleware('a'));

        self::assertFalse($pipeline->isEmpty());
    }

    #[Test]
    public function prependingMakesItNonEmpty(): void
    {
        $pipeline = new PostRoutingPipeline();
        $pipeline->prepend(new PostRoutingTagMiddleware('a'));

        self::assertFalse($pipeline->isEmpty());
    }

    #[Test]
    public function pipeAndPrependReturnThePipelineForChaining(): void
    {
        $pipeline = new PostRoutingPipeline();

        self::assertSame($pipeline, $pipeline->pipe(new PostRoutingTagMiddleware('a')));
        self::assertSame($pipeline, $pipeline->prepend(new PostRoutingTagMiddleware('b')));
    }

    #[Test]
    public function itSatisfiesThePublicPipelineContract(): void
    {
        self::assertInstanceOf(MiddlewarePipelineInterface::class, new PostRoutingPipeline());
    }

    #[Test]
    public function dispatchRunsPipedMiddlewareInRegistrationOrderAroundTheHandler(): void
    {
        $log = new PostRoutingLog();
        $pipeline = new PostRoutingPipeline();
        $pipeline->pipe(new PostRoutingTagMiddleware('outer', $log));
        $pipeline->pipe(new PostRoutingTagMiddleware('inner', $log));

        $response = $pipeline->dispatch(
            $this->request(),
            static function (ServerRequestInterface $request) use ($log): ResponseInterface {
                $log->entries[] = 'handler';

                return Response::html('ok');
            },
        );

        self::assertSame(['outer', 'inner', 'handler'], $log->entries);
        self::assertSame('ok', (string) $response->getBody());
    }

    #[Test]
    public function prependPlacesMiddlewareOutermost(): void
    {
        $log = new PostRoutingLog();
        $pipeline = new PostRoutingPipeline();
        $pipeline->pipe(new PostRoutingTagMiddleware('first-piped', $log));
        $pipeline->prepend(new PostRoutingTagMiddleware('prepended', $log));

        (void) $pipeline->dispatch(
            $this->request(),
            static fn(ServerRequestInterface $request): ResponseInterface => Response::html('ok'),
        );

        self::assertSame(['prepended', 'first-piped'], $log->entries);
    }

    #[Test]
    public function dispatchOnAnEmptyPipelineCallsTheHandlerDirectly(): void
    {
        $response = new PostRoutingPipeline()->dispatch(
            $this->request(),
            static fn(ServerRequestInterface $request): ResponseInterface => Response::html('direct'),
        );

        self::assertSame('direct', (string) $response->getBody());
    }

    #[Test]
    public function middlewareCanMutateTheRequestSeenByTheHandler(): void
    {
        $pipeline = new PostRoutingPipeline();
        $pipeline->pipe(new PostRoutingAttributeMiddleware('_bound_models', ['user' => 'sentinel']));

        $response = $pipeline->dispatch(
            $this->request(),
            static function (ServerRequestInterface $request): ResponseInterface {
                /** @var array<string, string> $bound */
                $bound = $request->getAttribute('_bound_models');

                return Response::html($bound['user']);
            },
        );

        self::assertSame('sentinel', (string) $response->getBody());
    }

    #[Test]
    public function classNameMiddlewareIsResolvedThroughTheContainer(): void
    {
        $log = new PostRoutingLog();
        $container = new Container();
        $container->instance(PostRoutingTagMiddleware::class, new PostRoutingTagMiddleware('from-container', $log));

        $pipeline = new PostRoutingPipeline($container);
        $pipeline->pipe(PostRoutingTagMiddleware::class);

        (void) $pipeline->dispatch(
            $this->request(),
            static fn(ServerRequestInterface $request): ResponseInterface => Response::html('ok'),
        );

        self::assertSame(['from-container'], $log->entries);
    }

    /**
     * The kernel snapshots this at boot() and restores it at shutdown(). Without
     * the round trip a recycled worker would stack a second copy of every
     * post-routing middleware and run each one twice per request.
     */
    #[Test]
    public function snapshotAndRestoreRoundTripTheStack(): void
    {
        $pipeline = new PostRoutingPipeline();
        $baseline = $pipeline->snapshot();

        $pipeline->pipe(new PostRoutingTagMiddleware('boot'));
        self::assertFalse($pipeline->isEmpty());

        $pipeline->restoreFromSnapshot($baseline);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function restoreKeepsMiddlewareRegisteredBeforeTheSnapshot(): void
    {
        $log = new PostRoutingLog();
        $pipeline = new PostRoutingPipeline();
        $pipeline->pipe(new PostRoutingTagMiddleware('pre-boot', $log));

        $baseline = $pipeline->snapshot();
        $pipeline->pipe(new PostRoutingTagMiddleware('boot', $log));
        $pipeline->restoreFromSnapshot($baseline);

        (void) $pipeline->dispatch(
            $this->request(),
            static fn(ServerRequestInterface $request): ResponseInterface => Response::html('ok'),
        );

        self::assertFalse($pipeline->isEmpty());
        self::assertSame(['pre-boot'], $log->entries);
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest(method: 'GET', uri: 'http://localhost/users/1');
    }
}

final class PostRoutingLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class PostRoutingTagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $tag,
        private ?PostRoutingLog $log = null,
    ) {}

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->log !== null) {
            $this->log->entries[] = $this->tag;
        }

        return $handler->handle($request);
    }
}

final readonly class PostRoutingAttributeMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, string> $value
     */
    public function __construct(private string $name, private array $value) {}

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler->handle($request->withAttribute($this->name, $this->value));
    }
}
