<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Middleware\SensitiveOperationMiddleware;
use Pulsar\Auth\Security\AccountTakeoverGuard;
use Pulsar\Auth\Security\SensitiveOperation;
use Pulsar\Auth\SecurityContext;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SessionConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AuthWiring;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\DispatchedRouteAwareInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Tests\Benchmark\Support\InMemoryAuditSink;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * `AccountTakeoverGuard` was built, documented, and constructed by nothing. No
 * request path reached it, so the operations it names ran with no comparison
 * between the address and device a credential change arrives from and the ones
 * the session was opened on.
 *
 * The composition root now builds it behind the `sensitive` alias. These tests
 * assert reachability the only way that means anything: pull the alias out of
 * the registry an application routes against, feed it a takeover, and check the
 * handler was never entered and the attempt is in the audit chain. Asserting
 * that the container holds an `AccountTakeoverGuard` instance would pass
 * against a guard aliased to nothing, which is the defect being fixed.
 */
#[CoversClass(AuthWiring::class)]
#[CoversClass(SensitiveOperationMiddleware::class)]
final class SensitiveOperationWiringTest extends TestCase
{
    private const string SESSION_IP = '203.0.113.10';
    private const string SESSION_UA = 'Mozilla/5.0 (Macintosh)';
    private const string ATTACKER_IP = '198.51.100.4';
    private const string ATTACKER_UA = 'curl/8.5.0';
    private const string USER_ID = 'user-91';

    private InMemoryAuditSink $sink;
    private SessionManager $session;

    protected function setUp(): void
    {
        $this->sink = new InMemoryAuditSink();
        $this->session = new SessionManager(new ArrayHandler(), $this->sessionConfig());
        $this->session->startWithRequest($this->request(self::SESSION_IP, self::SESSION_UA));
    }

    #[Test]
    public function theSensitiveAliasResolvesToTheTakeoverMiddleware(): void
    {
        $registry = $this->wire();

        self::assertTrue($registry->hasAlias('sensitive'), 'no route can reach a guard that has no alias');
        self::assertInstanceOf(SensitiveOperationMiddleware::class, $this->sensitiveMiddleware($registry));
    }

    /**
     * The effect, through the alias an application actually routes against.
     */
    #[Test]
    public function aTakeoverAttemptRoutedThroughTheAliasIsBlockedAndAudited(): void
    {
        $registry = $this->wire();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->sensitiveMiddleware($registry)->process(
            $this->passwordChangeRequest(self::ATTACKER_IP, self::ATTACKER_UA),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        $entries = $this->sink->entries();
        self::assertCount(1, $entries);
        self::assertSame('auth.takeover.high_risk', $entries[0]->action);
        self::assertSame(AuditOutcome::Denied, $entries[0]->outcome);
        self::assertSame(self::USER_ID, $entries[0]->actor);
    }

    /**
     * The guard needs the session's request metadata to compare against and the
     * session guard's stamp of when credentials were presented. Without a
     * session there is nothing to compare, so the alias must be absent rather
     * than resolve to a middleware that cannot evaluate anything — an alias
     * pointing at a half-built control is the same unreachable control in a
     * less obvious form.
     */
    #[Test]
    public function withoutASessionTheAliasIsNotRegisteredAtAll(): void
    {
        $container = new Container();
        $container->instance(AuditLogger::class, $this->auditLogger());
        $container->instance(LoggerInterface::class, new NullLogger());

        $registry = new MiddlewareRegistry();

        new AuthWiring()->wire(
            $container,
            $this->configManager(),
            new MiddlewarePipeline($container),
            $registry,
            new Router(),
        );

        self::assertFalse($registry->hasAlias('sensitive'));
        self::assertFalse($container->has(AccountTakeoverGuard::class));
    }

    private function wire(): MiddlewareRegistry
    {
        $auditLogger = $this->auditLogger();

        $container = new Container();
        $container->instance(SessionInterface::class, $this->session);
        $container->instance(SessionManager::class, $this->session);
        $container->instance(AuditLogger::class, $auditLogger);
        $container->instance(AuditLoggerInterface::class, $auditLogger);
        $container->instance(LoggerInterface::class, new NullLogger());

        $registry = new MiddlewareRegistry();

        new AuthWiring()->wire(
            $container,
            $this->configManager(),
            new MiddlewarePipeline($container),
            $registry,
            new Router(),
        );

        // The session must carry an authenticated identity for the sensitive
        // path to reach the risk comparison at all, and the login stamp is what
        // keeps the request inside the re-authentication window so the refusal
        // under test is the takeover one rather than a stale-session one.
        new SessionGuard($this->session)->login($this->user());

        return $registry;
    }

    /**
     * The alias, resolved and then bound to the route being dispatched — which
     * is what {@see \Pulsar\Http\Middleware\MiddlewarePipeline::dispatch()}
     * does for every {@see DispatchedRouteAwareInterface} middleware before it
     * builds the chain. The route is the channel this control reads; an unbound
     * copy names no operation and would pass everything through.
     */
    private function sensitiveMiddleware(MiddlewareRegistry $registry): MiddlewareInterface
    {
        $resolved = $registry->resolve('sensitive');
        self::assertCount(1, $resolved);

        $middleware = $resolved[0];
        self::assertInstanceOf(DispatchedRouteAwareInterface::class, $middleware);

        $bound = $middleware->forDispatchedRoute($this->passwordChangeRoute());
        self::assertInstanceOf(MiddlewareInterface::class, $bound);

        return $bound;
    }

    private function passwordChangeRoute(): MatchedRoute
    {
        return new MatchedRoute(new Route(
            methods: [Method::POST],
            path: '/account/password',
            handler: 'noop',
            attributes: ['sensitive_operation' => SensitiveOperation::PasswordChange],
            middleware: ['auth', 'sensitive'],
        ));
    }

    private function auditLogger(): AuditLogger
    {
        return new AuditLogger($this->sink, random_bytes(32));
    }

    private function user(): Identity
    {
        return new Identity(self::USER_ID, 'Sensitive User', ['user']);
    }

    private function request(string $ip, string $userAgent): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: '/account/password',
            headers: ['User-Agent' => $userAgent],
            serverParams: ['REMOTE_ADDR' => $ip],
        );
    }

    private function passwordChangeRequest(string $ip, string $userAgent): ServerRequestInterface
    {
        // No `_route`: {@see sensitiveMiddleware()} binds the dispatched route,
        // which is the channel the middleware reads.
        $request = $this->request($ip, $userAgent);

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($this->user());

        return $request->withAttribute('_security_context', new SecurityContext($authManager, $request));
    }

    private function sessionConfig(): SessionConfig
    {
        return new SessionConfig(
            cookieName: 'PULSAR_TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );
    }

    private function configManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_sensitive_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents(
            $configPath . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $configPath . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $configPath . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => [], '
            . '"auth" => ["default_guard" => "session", "two_factor" => ["enabled" => false], '
            . '"authorization" => ["roles" => [], "super_roles" => []]]];',
        );

        $manager = new ConfigManager($configPath);
        $manager->load();

        return $manager;
    }
}
