<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Controller;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Controller\HealthController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(HealthController::class)]
final class HealthControllerTest extends TestCase
{
    private const string OPERATOR_TOKEN = 'operator-token';


    #[Test]
    public function returns200JsonWhenAllChecksHealthy(): void
    {
        $results = [
            HealthCheckResult::healthy('cache', 'OK', 1.5),
            HealthCheckResult::healthy('disk', '500 MB free', 0.3),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: $results,
            generatedAt: new DateTimeImmutable('2026-03-09T12:00:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        self::assertSame('healthy', $body['status']);
        self::assertArrayHasKey('checks', $body);

        $checks = $body['checks'];
        self::assertIsArray($checks);
        self::assertArrayHasKey('cache', $checks);
        self::assertArrayHasKey('disk', $checks);

        $cacheCheck = $checks['cache'];
        self::assertIsArray($cacheCheck);
        self::assertSame('healthy', $cacheCheck['status']);

        $diskCheck = $checks['disk'];
        self::assertIsArray($diskCheck);
        self::assertSame('healthy', $diskCheck['status']);

        self::assertArrayHasKey('timestamp', $body);
    }

    #[Test]
    public function returns503JsonWhenAnyCheckUnhealthy(): void
    {
        $results = [
            HealthCheckResult::healthy('cache', 'OK', 1.0),
            HealthCheckResult::unhealthy('database', 'Connection refused', 50.0),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Unhealthy,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner, self::operatorAuthorizer());
        $response = $controller(self::operatorRequest());

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('unhealthy', $body['status']);

        $checks = $body['checks'];
        self::assertIsArray($checks);

        $dbCheck = $checks['database'];
        self::assertIsArray($dbCheck);
        self::assertSame('unhealthy', $dbCheck['status']);
        self::assertArrayHasKey('message', $dbCheck);
        self::assertIsString($dbCheck['message']);
        self::assertStringContainsString('Connection refused', $dbCheck['message']);
    }

    #[Test]
    public function returns503JsonWhenAnyCheckDegraded(): void
    {
        $results = [
            HealthCheckResult::degraded('cache', 'Slow response', 600.0),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Degraded,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('degraded', $body['status']);
    }

    #[Test]
    public function responseIncludesCheckDetails(): void
    {
        $results = [
            HealthCheckResult::healthy('cache', 'Cache responded in 2.5ms', 2.5),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner, self::operatorAuthorizer());
        $response = $controller(self::operatorRequest());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        $checks = $body['checks'];
        self::assertIsArray($checks);

        $cacheCheck = $checks['cache'];
        self::assertIsArray($cacheCheck);
        self::assertSame('Cache responded in 2.5ms', $cacheCheck['message']);
        self::assertSame(2.5, $cacheCheck['latency_ms']);
    }

    /**
     * The probe has to answer an unauthenticated load balancer, which is exactly
     * why it is the wrong place to publish the estate behind it. Check MESSAGES
     * carry absolute filesystem paths, database hostnames and driver errors; the
     * latencies are a timing side channel. An anonymous caller gets the overall
     * status and each check's status, and nothing else.
     */
    #[Test]
    public function anAnonymousProbeSeesStatusesButNoMessagesOrLatencies(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Unhealthy,
            results: [
                HealthCheckResult::unhealthy(
                    'database',
                    'Database check failed: SQLSTATE[08006] could not connect to host db-01.internal',
                    50.0,
                ),
                HealthCheckResult::healthy('disk', '812 MB free on /srv/app/var', 0.4),
            ],
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        // The authorizer is the same one the detailed tests use; only the
        // credential differs. That is the whole property under test.
        $response = new HealthController($runner, self::operatorAuthorizer())(self::anonymousRequest());

        // The load balancer's contract is untouched.
        self::assertSame(503, $response->getStatusCode());

        $raw = (string) $response->getBody();

        self::assertStringNotContainsString('db-01.internal', $raw);
        self::assertStringNotContainsString('/srv/app/var', $raw);
        self::assertStringNotContainsString('latency_ms', $raw);

        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('unhealthy', $body['status']);

        $checks = $body['checks'];
        self::assertIsArray($checks);
        self::assertSame(['status' => 'unhealthy'], $checks['database']);
        self::assertSame(['status' => 'healthy'], $checks['disk']);
    }

    /**
     * With no authorizer wired at all, nothing is in a position to call a
     * request privileged, and that is a refusal rather than a waiver.
     */
    #[Test]
    public function detailIsWithheldWhenNoAuthorizerIsWired(): void
    {
        // Even with the operator token in hand: no authorizer means nothing can
        // vouch for the request, and an absent vouching mechanism is a refusal.
        $response = new HealthController($this->diskOnlyRunner())(self::operatorRequest());

        self::assertStringNotContainsString('/srv/app/var', (string) $response->getBody());
    }

    /** And with no request at all, there is nothing to authorize. */
    #[Test]
    public function detailIsWithheldWhenThereIsNoRequest(): void
    {
        $response = new HealthController($this->diskOnlyRunner(), self::operatorAuthorizer())(null);

        self::assertStringNotContainsString('/srv/app/var', (string) $response->getBody());
    }

    /** An unauthorized request is refused detail even when an authorizer exists. */
    #[Test]
    public function detailIsWithheldFromAnUnauthorizedRequest(): void
    {
        $controller = new HealthController($this->diskOnlyRunner(), self::operatorAuthorizer());

        self::assertStringNotContainsString(
            '/srv/app/var',
            (string) $controller(self::anonymousRequest())->getBody(),
        );

        // The same controller, the same runner, one header apart.
        self::assertStringContainsString(
            '/srv/app/var',
            (string) $controller(self::operatorRequest())->getBody(),
        );
    }

    private function diskOnlyRunner(): HealthCheckRunnerInterface
    {
        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn(new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [HealthCheckResult::healthy('disk', '812 MB free on /srv/app/var', 0.4)],
            generatedAt: new DateTimeImmutable(),
        ));

        return $runner;
    }

    /**
     * Stands in for the guard ResilienceWiring builds: detail is granted to a
     * request presenting the operator Bearer token and to no other.
     *
     * The predicate reads the request rather than returning a constant on
     * purpose. A gate that always says yes proves the detailed branch renders;
     * it proves nothing about the gate. Every test below drives the same
     * authorizer and varies only the credential.
     *
     * @return Closure(ServerRequestInterface): bool
     */
    private static function operatorAuthorizer(): Closure
    {
        return static fn(ServerRequestInterface $request): bool
            => $request->getHeaderLine('Authorization') === 'Bearer ' . self::OPERATOR_TOKEN;
    }

    private static function operatorRequest(): ServerRequest
    {
        return new ServerRequest(
            uri: '/health',
            headers: ['Authorization' => 'Bearer ' . self::OPERATOR_TOKEN],
        );
    }

    private static function anonymousRequest(): ServerRequest
    {
        return new ServerRequest(uri: '/health');
    }

    #[Test]
    public function responseIncludesTimestamp(): void
    {
        $generatedAt = new DateTimeImmutable('2026-03-09T15:30:00+00:00');
        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [],
            generatedAt: $generatedAt,
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        self::assertSame('2026-03-09T15:30:00+00:00', $body['timestamp']);
    }
}
