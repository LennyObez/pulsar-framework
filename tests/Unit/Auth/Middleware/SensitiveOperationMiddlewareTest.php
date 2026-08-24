<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Middleware\SensitiveOperationMiddleware;
use Pulsar\Auth\Middleware\StepUpMiddleware;
use Pulsar\Auth\Security\AccountTakeoverGuard;
use Pulsar\Auth\Security\SensitiveOperation;
use Pulsar\Auth\SecurityContext;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Tests\Benchmark\Support\InMemoryAuditSink;

use function array_map;
use function implode;
use function random_bytes;
use function time;

/**
 * `AccountTakeoverGuard` was constructed nowhere. Nothing compared the address
 * and device a credential change arrived from against the ones the session was
 * opened on, and nothing enforced the re-authentication window the guard
 * describes — so every operation it names (password change, e-mail change, MFA
 * disable, recovery-code regeneration, account deletion) ran on the strength of
 * a session cookie alone. `SensitiveOperationMiddleware`, behind the
 * `sensitive` alias, is where the guard now sits.
 *
 * These tests assert what changes, not that the object exists: the handler is
 * never entered, the response is a 403, and the attempt is in the audit chain
 * naming the account it was made against. A test that only asserted the guard
 * had been constructed would pass against the defect this replaces.
 */
#[CoversClass(SensitiveOperationMiddleware::class)]
#[CoversClass(AccountTakeoverGuard::class)]
final class SensitiveOperationMiddlewareTest extends TestCase
{
    private const string SESSION_IP = '203.0.113.10';
    private const string SESSION_UA = 'Mozilla/5.0 (Macintosh)';
    private const string ATTACKER_IP = '198.51.100.4';
    private const string ATTACKER_UA = 'curl/8.5.0';
    private const string USER_ID = 'user-91';

    private InMemoryAuditSink $sink;
    private string $auditKey;
    private AuditLogger $auditLogger;
    private SessionManager $session;
    private SessionGuard $sessionGuard;

    protected function setUp(): void
    {
        $this->sink = new InMemoryAuditSink();
        $this->auditKey = random_bytes(32);
        $this->auditLogger = new AuditLogger($this->sink, $this->auditKey);

        $this->session = new SessionManager(new ArrayHandler(), $this->sessionConfig());
        $this->session->startWithRequest($this->request(self::SESSION_IP, self::SESSION_UA));
        $this->sessionGuard = new SessionGuard($this->session);
    }

    /**
     * The case the guard exists for: a password change arriving from another
     * address on another device than the one the session was opened on.
     */
    #[Test]
    public function takeoverAttemptIsBlockedAndNeverReachesTheHandler(): void
    {
        $this->sessionGuard->login($this->user());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware()->process(
            $this->sensitiveRequest(self::ATTACKER_IP, self::ATTACKER_UA),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * Blocking without a record is half a control: the assessor's question is
     * what the framework knew, and about whom. The entry must name the account
     * the attempt was made against rather than a placeholder.
     */
    #[Test]
    public function blockedTakeoverIsRecordedInTheAuditChainAgainstTheAccount(): void
    {
        $this->sessionGuard->login($this->user());

        $this->middleware()->process(
            $this->sensitiveRequest(self::ATTACKER_IP, self::ATTACKER_UA),
            $this->passingHandler(),
        );

        $entry = $this->onlyEntry();

        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame('auth.takeover.high_risk', $entry->action);
        self::assertSame(self::USER_ID, $entry->actor);
        self::assertSame(SensitiveOperation::PasswordChange->value, $entry->resource);
        self::assertSame(self::SESSION_IP, $entry->metadata['session_ip']);
        self::assertSame(self::ATTACKER_IP, $entry->metadata['current_ip']);
    }

    /**
     * A record an operator can rewrite is a log, not an audit trail. Editing
     * the outcome of a recorded takeover must break verification.
     */
    #[Test]
    public function theRecordedTakeoverCannotBeRewritten(): void
    {
        $this->sessionGuard->login($this->user());

        $this->middleware()->process(
            $this->sensitiveRequest(self::ATTACKER_IP, self::ATTACKER_UA),
            $this->passingHandler(),
        );

        $entry = $this->onlyEntry();
        self::assertTrue($entry->verify($this->auditKey));

        $rewritten = new AuditEntry(
            id: $entry->id,
            event: $entry->event,
            outcome: AuditOutcome::Success,
            actor: $entry->actor,
            action: $entry->action,
            resource: $entry->resource,
            timestamp: $entry->timestamp,
            metadata: $entry->metadata,
            previousHmac: $entry->previousHmac,
            hmac: $entry->hmac,
        );

        self::assertFalse($rewritten->verify($this->auditKey));
    }

    /**
     * The classic takeover needs no address change at all: the attacker replays
     * a stolen cookie from a session that authenticated hours ago. The window,
     * not the network, is what refuses it.
     */
    #[Test]
    public function staleAuthenticationIsRefusedAndRecorded(): void
    {
        $this->sessionGuard->login($this->user());
        $this->ageAuthentication(600);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware()->process(
            $this->sensitiveRequest(self::SESSION_IP, self::SESSION_UA),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        $entry = $this->onlyEntry();
        self::assertSame('auth.sensitive_operation.refused', $entry->action);
        self::assertSame('reauthentication_required', $entry->metadata['reason']);
        self::assertSame(self::USER_ID, $entry->actor);
    }

    /**
     * A step-up challenge is a re-authentication. Accepting it keeps the two
     * controls from prompting twice for one request.
     */
    #[Test]
    public function aCompletedStepUpSubstitutesForAFreshLogin(): void
    {
        $this->sessionGuard->login($this->user());
        $this->ageAuthentication(600);
        StepUpMiddleware::markStepUpAuthenticated($this->session, self::USER_ID);

        $response = $this->middleware()->process(
            $this->sensitiveRequest(self::SESSION_IP, self::SESSION_UA),
            $this->passingHandler(),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame([], $this->sink->entries());
    }

    /**
     * The control must not stand in the way of the ordinary case, or an
     * application will take it back off the route.
     */
    #[Test]
    public function freshAuthenticationFromTheSameDeviceProceeds(): void
    {
        $this->sessionGuard->login($this->user());

        $response = $this->middleware()->process(
            $this->sensitiveRequest(self::SESSION_IP, self::SESSION_UA),
            $this->passingHandler(),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('handled', (string) $response->getBody());
    }

    /**
     * A mobile network re-issuing an address is ordinary; a swapped device is
     * not. One indicator passes and is recorded, so an operator can see the
     * pattern without the account holder meeting a wall.
     */
    #[Test]
    public function anAddressChangeAloneIsAllowedButRecorded(): void
    {
        $this->sessionGuard->login($this->user());

        $response = $this->middleware()->process(
            $this->sensitiveRequest(self::ATTACKER_IP, self::SESSION_UA),
            $this->passingHandler(),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());

        $entry = $this->onlyEntry();
        self::assertSame('auth.takeover.elevated_risk', $entry->action);
        self::assertSame(self::USER_ID, $entry->actor);
    }

    /**
     * Fail-closed: a route carrying the alias but naming no operation the guard
     * recognises cannot be evaluated, so it is refused rather than waved
     * through.
     */
    #[Test]
    public function aRouteThatNamesNoRecognisedOperationIsRefused(): void
    {
        $this->sessionGuard->login($this->user());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $bogus = new MatchedRoute(new Route(
            methods: [Method::POST],
            path: '/account/password',
            handler: 'noop',
            attributes: ['sensitive_operation' => 'not_a_real_operation'],
        ));

        $response = $this->middleware(dispatchedRoute: $bogus)->process(
            $this->withIdentity($this->request(self::SESSION_IP, self::SESSION_UA), $this->user()),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('operation_not_declared', $this->onlyEntry()->metadata['reason']);
    }

    /**
     * An unauthenticated caller reaching a sensitive route is refused, and
     * recorded as anonymous rather than losing the entry to a null actor.
     */
    #[Test]
    public function anUnauthenticatedCallerIsRefusedAndRecordedAsAnonymous(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware()->process(
            $this->withIdentity(
                $this->routedRequest(self::SESSION_IP, self::SESSION_UA),
                new AnonymousIdentity(),
            ),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        $entry = $this->onlyEntry();
        self::assertSame('unauthenticated', $entry->metadata['reason']);
        self::assertSame('anonymous', $entry->actor);
    }

    /**
     * The sink is the one collaborator that fails for reasons unrelated to the
     * request — a full disk, an unreachable database. When it does, the
     * sensitive operation must still not happen: a control that opens when its
     * recorder breaks is a control an attacker can switch off by filling a
     * disk.
     */
    #[Test]
    public function aFailingAuditSinkStillBlocksTheOperation(): void
    {
        $this->sessionGuard->login($this->user());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware($this->refusingAuditLogger())->process(
            $this->sensitiveRequest(self::ATTACKER_IP, self::ATTACKER_UA),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * The same property on the refusal path, which writes its own entry: a
     * broken sink must not let a stale session through.
     */
    #[Test]
    public function aFailingAuditSinkStillRefusesAStaleSession(): void
    {
        $this->sessionGuard->login($this->user());
        $this->ageAuthentication(600);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware($this->refusingAuditLogger())->process(
            $this->sensitiveRequest(self::SESSION_IP, self::SESSION_UA),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * The middleware as the pipeline hands it to a dispatch: bound to the route
     * whose handler is about to run.
     *
     * The route is an ARGUMENT, not the `_route` attribute. This is the
     * route-level `sensitive` alias, so other route middleware runs in front of
     * it, and a substituted route that simply omits `sensitive_operation` used to
     * skip the whole control.
     */
    /**
     * A substituted `_route` cannot switch the control off.
     *
     * This is the route-level `sensitive` alias, so other route middleware runs
     * in front of it and can hand the next frame any route it likes while the
     * kernel goes on dispatching the one it matched. The bypass needed no
     * forgery of the declaration — a route that simply OMITS
     * `sensitive_operation` made the lookup return null, and the guard, the
     * re-authentication window and the audit entry were all skipped for an
     * operation the real route declares.
     */
    #[Test]
    public function aRouteSubstitutedOnTheRequestCannotSkipTheGuard(): void
    {
        $this->sessionGuard->login($this->user());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        // What a frame ahead of this one can write: a route declaring nothing.
        $undeclared = new MatchedRoute(new Route(
            methods: [Method::POST],
            path: '/account/password',
            handler: 'noop',
        ));

        $response = $this->middleware()->process(
            $this->sensitiveRequest(self::ATTACKER_IP, self::ATTACKER_UA)
                ->withAttribute('_route', $undeclared),
            $handler,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('auth.takeover.high_risk', $this->onlyEntry()->action);
    }

    private function middleware(
        ?AuditLogger $auditLogger = null,
        ?MatchedRoute $dispatchedRoute = null,
    ): SensitiveOperationMiddleware {
        $auditLogger ??= $this->auditLogger;

        return new SensitiveOperationMiddleware(
            new AccountTakeoverGuard(
                logger: new NullLogger(),
                reauthWindowSeconds: 300,
                auditLogger: $auditLogger,
            ),
            $this->sessionGuard,
            $this->session,
            $auditLogger,
        )->forDispatchedRoute($dispatchedRoute ?? $this->sensitiveRoute());
    }

    /** The route every test here dispatches unless it says otherwise. */
    private function sensitiveRoute(): MatchedRoute
    {
        return new MatchedRoute(new Route(
            methods: [Method::POST],
            path: '/account/password',
            handler: 'noop',
            attributes: ['sensitive_operation' => SensitiveOperation::PasswordChange],
            middleware: ['auth', 'sensitive'],
        ));
    }

    private function refusingAuditLogger(): AuditLogger
    {
        return new AuditLogger(
            new class implements AuditSinkInterface {
                public function write(AuditEntry $entry): void
                {
                    throw SecurityException::auditWriteFailed('sink unavailable');
                }
            },
            $this->auditKey,
        );
    }

    private function user(): Identity
    {
        return new Identity(self::USER_ID, 'Sensitive User', ['user']);
    }

    /**
     * Push the session's proof of identity back in time. `login()` stamps
     * `time()` and no public API rewinds it, so the stored value is edited to
     * the shape an aged session presents on a later request.
     */
    private function ageAuthentication(int $seconds): void
    {
        $this->session->set('_pulsar_authenticated_at', time() - $seconds);
    }

    private function passingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(statusCode: ResponseStatus::OK->value, body: 'handled');
            }
        };
    }

    private function onlyEntry(): AuditEntry
    {
        $entries = $this->sink->entries();

        self::assertCount(
            1,
            $entries,
            'expected exactly one audit entry, got: ' . implode(', ', array_map(
                static fn(AuditEntry $entry): string => $entry->action,
                $entries,
            )),
        );

        return $entries[0];
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

    private function routedRequest(string $ip, string $userAgent): ServerRequestInterface
    {
        // No `_route`: the middleware is bound to the dispatched route by
        // {@see middleware()}, which is the channel it reads.
        return $this->request($ip, $userAgent);
    }

    private function sensitiveRequest(string $ip, string $userAgent): ServerRequestInterface
    {
        return $this->withIdentity($this->routedRequest($ip, $userAgent), $this->user());
    }

    private function withIdentity(ServerRequestInterface $request, IdentityInterface $identity): ServerRequestInterface
    {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

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
}
