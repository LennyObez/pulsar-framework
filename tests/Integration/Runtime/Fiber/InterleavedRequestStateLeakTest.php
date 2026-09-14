<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime\Fiber;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeManager;
use Pulsar\Runtime\Fiber\CooperativeSleep;
use Pulsar\Runtime\Fiber\FiberScheduler;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\ResettableInterface;
use Socket;
use stdClass;

use function hrtime;
use function socket_close;
use function socket_create_pair;

/**
 * Observes the cross-request state leak that makes >1 connection fiber unsafe.
 *
 * ADR-0060: a check never observed to fail is indistinguishable from no check.
 * {@see \Pulsar\Runtime\PersistentRuntime} refuses `fiber_concurrency > 1`, and
 * this suite is the evidence for that refusal rather than an assertion about
 * it — it drives the real {@see FiberScheduler} with the real
 * {@see RequestSandbox} and {@see ScopeManager} and watches one request destroy
 * another's state.
 *
 * Both cases here PASS on the code as it stands. That is the point: they
 * document why the configuration is refused instead of repaired, because
 * repairing it means fiber-keying every `ResettableInterface` singleton in the
 * framework and in application code, plus superglobal hygiene, which is
 * process-global by definition.
 */
#[CoversClass(RequestSandbox::class)]
#[CoversClass(ScopeManager::class)]
#[CoversClass(FiberScheduler::class)]
final class InterleavedRequestStateLeakTest extends TestCase
{
    /** @var list<Socket> */
    private array $socketsToClose = [];

    protected function tearDown(): void
    {
        foreach ($this->socketsToClose as $socket) {
            @socket_close($socket);
        }

        $this->socketsToClose = [];
    }

    private function socket(): Socket
    {
        $pair = [];
        $domain = PHP_OS_FAMILY === 'Windows' ? AF_INET : AF_UNIX;
        $protocol = PHP_OS_FAMILY === 'Windows' ? SOL_TCP : 0;
        socket_create_pair($domain, SOCK_STREAM, $protocol, $pair);
        /** @var array{Socket, Socket} $pair */
        $this->socketsToClose[] = $pair[0];
        $this->socketsToClose[] = $pair[1];

        return $pair[0];
    }

    /**
     * Pump the loop until every fiber has finished or the safety deadline hits.
     */
    private function drain(FiberScheduler $scheduler): void
    {
        $deadline = hrtime(true) + 2_000_000_000;

        while ($scheduler->activeFiberCount() > 0 && hrtime(true) < $deadline) {
            $scheduler->tick(0.005);
        }

        self::assertSame(0, $scheduler->activeFiberCount(), 'both connection fibers must finish');
    }

    #[Test]
    public function aSuspendedRequestComesBackHoldingTheOtherRequestsSession(): void
    {
        // A singleton with per-request state and no fiber keying — the shape of
        // SessionManager, FlagEvaluationLog and CacheManager, all of which
        // RuntimeWiring registers as resettable.
        $session = new LeakProbeSession();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($session);

        $registry = new RequestResetRegistry();
        $registry->registerResettable(LeakProbeSession::class);

        $sandbox = new RequestSandbox($container, $registry, new LeakDetector());
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $observed = new LeakProbeObservations();
        $scheduler = new FiberScheduler();

        // Request A: opens its session, then suspends inside "kernel->handle()"
        // exactly as a contended cache lock would, then reads its session back.
        $scheduler->spawn($this->socket(), static function () use ($sandbox, $session, $request, $response, $observed): void {
            $sandbox->beforeRequest($request);
            $session->open('caller-a');

            CooperativeSleep::forMilliseconds(40);

            $observed->readBack = $session->owner;
            $sandbox->afterRequest($request, $response);
        });

        // Request B is admitted while A is parked and runs start to finish.
        $scheduler->spawn($this->socket(), static function () use ($sandbox, $session, $request, $response): void {
            $sandbox->beforeRequest($request);
            $session->open('caller-b');
            $sandbox->afterRequest($request, $response);
        });

        $this->drain($scheduler);

        // A wrote 'caller-a' and read back something else: either B's identity
        // (B's open() found the session already started, as SessionManager's
        // start() does) or the empty string B's afterRequest() reset it to.
        self::assertNotSame(
            'caller-a',
            $observed->readBack,
            'request A must have kept its own session — it did not',
        );
        self::assertContains($observed->readBack, ['caller-b', ''], 'A came back holding B state');
    }

    #[Test]
    public function aSuspendedRequestLosesItsRequestScopedContainerInstances(): void
    {
        // ScopeManager keeps ONE request-scoped pool for the whole process.
        $scopes = new ScopeManager();
        $observed = new LeakProbeObservations();
        $scheduler = new FiberScheduler();

        $scheduler->spawn($this->socket(), static function () use ($scopes, $observed): void {
            $scopes->beginScope(Lifetime::RequestScope);
            $scopes->setScopedInstance('per-request', Lifetime::RequestScope, new stdClass());

            CooperativeSleep::forMilliseconds(40);

            $observed->stillScoped = $scopes->isActive(Lifetime::RequestScope);
            $observed->instanceSurvived = $scopes->getScopedInstance('per-request', Lifetime::RequestScope) !== null;
        });

        $scheduler->spawn($this->socket(), static function () use ($scopes): void {
            $scopes->beginScope(Lifetime::RequestScope);
            $scopes->endScope(Lifetime::RequestScope);
        });

        $this->drain($scheduler);

        self::assertFalse(
            $observed->instanceSurvived,
            "request A's request-scoped instance must have survived its own request — it did not",
        );
        self::assertFalse(
            $observed->stillScoped,
            'request A was left outside the request scope it opened',
        );
    }
}

/**
 * A resettable singleton with per-request state and no fiber keying.
 *
 * `open()` mirrors `SessionManager::start()`: it is a no-op once started, so a
 * second caller arriving mid-flight silently adopts the first caller's session
 * rather than loading its own.
 *
 * @internal
 */
final class LeakProbeSession implements ResettableInterface
{
    public string $owner = '';

    private bool $started = false;

    public function open(string $owner): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->owner = $owner;
    }

    #[Override]
    public function resetRequestState(): void
    {
        $this->started = false;
        $this->owner = '';
    }
}

/**
 * Mutable holder for what a fiber observed — a named class the analyser does
 * not assume is unchanged across the scheduler's indirect call.
 *
 * @internal
 */
final class LeakProbeObservations
{
    public string $readBack = '';

    public bool $stillScoped = false;

    public bool $instanceSurvived = false;
}
