<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\JustifiedAccessConfig;
use Pulsar\Security\JustifiedAccess\JustifiedAccessMiddleware;
use Pulsar\Security\JustifiedAccess\RequiresJustification;

#[CoversClass(JustifiedAccessMiddleware::class)]
final class JustifiedAccessMiddlewareTest extends TestCase
{
    public function testPassesThroughWhenDisabled(): void
    {
        $config = new JustifiedAccessConfig(enabled: false);
        $middleware = $this->createMiddleware($config);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = $this->createRequest();
        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    public function testPassesThroughWithoutAttribute(): void
    {
        $middleware = $this->createMiddleware();

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = $this->createRequest(requiresJustification: null);
        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    public function testDeniesAccessWithoutJustification(): void
    {
        $middleware = $this->createMiddleware();
        $handler = $this->createStub(RequestHandlerInterface::class);

        $request = $this->createRequest(
            requiresJustification: new RequiresJustification(),
            justificationHeader: '',
        );

        $response = $middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('message', $body);
        self::assertIsString($body['message']);
        self::assertStringContainsString('justification required', $body['message']);
    }

    public function testDeniesAccessWithTooShortJustification(): void
    {
        $config = new JustifiedAccessConfig(minJustificationLength: 20);
        $middleware = $this->createMiddleware($config);
        $handler = $this->createStub(RequestHandlerInterface::class);

        $request = $this->createRequest(
            requiresJustification: new RequiresJustification(),
            justificationHeader: 'too short',
        );

        $response = $middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testGrantsAccessWithValidJustification(): void
    {
        $store = $this->createMock(JustificationStoreInterface::class);
        $store->expects(self::once())->method('store');

        $middleware = $this->createMiddleware(store: $store);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = $this->createRequest(
            requiresJustification: new RequiresJustification(),
            justificationHeader: 'Customer requested access to their account records for dispute resolution',
        );

        $response = $middleware->process($request, $handler);
        self::assertSame($expectedResponse, $response);
    }

    public function testDeniesAccessWhenSupervisorApprovalRequired(): void
    {
        $config = new JustifiedAccessConfig(requireSupervisorFor: [DataClassification::Restricted]);
        $middleware = $this->createMiddleware($config);
        $handler = $this->createStub(RequestHandlerInterface::class);

        $request = $this->createRequest(
            requiresJustification: new RequiresJustification(dataClassification: DataClassification::Restricted),
            justificationHeader: 'Need to review restricted patient data for regulatory compliance audit',
            supervisorApproval: false,
        );

        $response = $middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('message', $body);
        self::assertIsString($body['message']);
        self::assertStringContainsString('Supervisor approval', $body['message']);
    }

    public function testGrantsAccessWithSupervisorApproval(): void
    {
        $config = new JustifiedAccessConfig(requireSupervisorFor: [DataClassification::Restricted]);
        $store = $this->createStub(JustificationStoreInterface::class);
        $middleware = $this->createMiddleware($config, $store);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = $this->createRequest(
            requiresJustification: new RequiresJustification(dataClassification: DataClassification::Restricted),
            justificationHeader: 'Need to review restricted data for regulatory compliance audit purposes',
            supervisorApproval: true,
        );

        $response = $middleware->process($request, $handler);
        self::assertSame($expectedResponse, $response);
    }

    private function createMiddleware(
        ?JustifiedAccessConfig $config = null,
        ?JustificationStoreInterface $store = null,
    ): JustifiedAccessMiddleware {
        return new JustifiedAccessMiddleware(
            $config ?? new JustifiedAccessConfig(),
            $store ?? $this->createStub(JustificationStoreInterface::class),
            $this->createStub(AuditLoggerInterface::class),
        );
    }

    private function createRequest(
        ?RequiresJustification $requiresJustification = null,
        string $justificationHeader = '',
        bool $supervisorApproval = false,
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/patient/123');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '10.0.0.1']);
        $request->method('getParsedBody')->willReturn(null);

        $request->method('getAttribute')->willReturnCallback(
            function (string $name) use ($requiresJustification): mixed {
                return match ($name) {
                    'requires_justification' => $requiresJustification,
                    'actor_id' => 'user-42',
                    'actor_name' => 'Dr. Smith',
                    'actor_role' => 'physician',
                    default => null,
                };
            },
        );

        $request->method('getHeaderLine')->willReturnCallback(
            function (string $name) use ($justificationHeader, $supervisorApproval): string {
                return match ($name) {
                    'X-Access-Justification' => $justificationHeader,
                    'X-Access-Justification-Category' => '',
                    'X-Supervisor-Approval' => $supervisorApproval ? 'true' : '',
                    default => '',
                };
            },
        );

        $request->method('withAttribute')->willReturn($request);

        return $request;
    }
}
