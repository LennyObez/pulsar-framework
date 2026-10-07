<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminSchemaMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(AdminSchemaMiddleware::class)]
final class AdminSchemaMiddlewareTest extends TestCase
{
    private PolicyInterface&Stub $policy;
    private IdentityInterface&Stub $identity;

    protected function setUp(): void
    {
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->identity = $this->createStub(IdentityInterface::class);
    }

    /**
     * @param list<string> $requireStepUpFor
     */
    private function makeMiddleware(
        bool $enabled = true,
        array $requireStepUpFor = ['drop', 'rename', 'drop_column', 'drop_index'],
    ): AdminSchemaMiddleware {
        $config = new AdminSchemaConfig(
            enabled: $enabled,
            requireStepUpFor: $requireStepUpFor,
        );

        return new AdminSchemaMiddleware($config, $this->policy);
    }

    private function makeRequest(
        string $method = 'GET',
        string $path = '/admin/schema',
        ?IdentityInterface $identity = null,
        bool $stepUpVerified = false,
        ?string $stepUpToken = null,
    ): ServerRequest {
        $headers = $stepUpToken !== null ? ['X-Step-Up-Token' => $stepUpToken] : [];

        $attributes = [];
        if ($identity !== null) {
            $attributes['identity'] = $identity;
        }
        if ($stepUpVerified) {
            $attributes['step_up_verified'] = true;
        }

        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
            attributes: $attributes,
        );
    }

    private function nextHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::json(['status' => 'ok']);
            }
        };
    }

    #[Test]
    public function returnsForbiddenWhenSchemaDisabled(): void
    {
        $middleware = $this->makeMiddleware(enabled: false);
        $request = $this->makeRequest(identity: $this->identity);

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Schema management is disabled', (string) $response->getBody());
    }

    #[Test]
    public function returnsUnauthorizedWhenNoIdentity(): void
    {
        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(identity: null);

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertStringContainsString('Authentication required', (string) $response->getBody());
    }

    #[Test]
    public function returnsForbiddenWhenSchemaViewDenied(): void
    {
        $this->policy->method('evaluate')->willReturn(false);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(identity: $this->identity);

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Schema viewing not permitted', (string) $response->getBody());
    }

    #[Test]
    public function allowsGetRequestWithViewPermission(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'GET',
            path: '/admin/schema/tables',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function allowsPreviewRouteWithoutOperationPermission(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/preview/create',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function detectsDeleteColumnOperation(): void
    {
        // First call: SchemaView => true, Second call: SchemaAlter => false
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                // First call is schema view (allow), second is schema alter (deny)
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users/columns/email',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('drop_column', (string) $response->getBody());
    }

    #[Test]
    public function detectsDeleteIndexOperation(): void
    {
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users/indexes/idx_email',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('drop_index', (string) $response->getBody());
    }

    #[Test]
    public function detectsDropTableOperation(): void
    {
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('drop', (string) $response->getBody());
    }

    #[Test]
    public function detectsRenameOperation(): void
    {
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables/users/rename',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('rename', (string) $response->getBody());
    }

    #[Test]
    public function detectsAlterColumnOperation(): void
    {
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables/users/columns',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('alter', (string) $response->getBody());
    }

    #[Test]
    public function detectsAlterIndexOperation(): void
    {
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables/users/indexes',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('alter', (string) $response->getBody());
    }

    #[Test]
    public function detectsCreateTableOperation(): void
    {
        $callCount = 0;
        $this->policy = $this->createStub(PolicyInterface::class);
        $this->policy->method('evaluate')->willReturnCallback(
            function () use (&$callCount): bool {
                $callCount++;
                return $callCount <= 1 ? true : false;
            },
        );

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('create', (string) $response->getBody());
    }

    #[Test]
    public function requiresStepUpForDestructiveOperation(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Step-up authentication required', (string) $response->getBody());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['step_up_required']);
    }

    #[Test]
    public function allowsDestructiveOperationWithStepUpVerified(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users',
            identity: $this->identity,
            stepUpVerified: true,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function allowsDestructiveOperationWithStepUpToken(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users',
            identity: $this->identity,
            stepUpToken: 'valid-token',
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function skipStepUpWhenNotInRequireList(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        // Create operation is NOT in requireStepUpFor
        $middleware = $this->makeMiddleware(requireStepUpFor: ['drop']);
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function putRequestReturnsNull(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'PUT',
            path: '/admin/schema/tables/users',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function stepUpRequiredForRenameOperation(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables/users/rename',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Step-up authentication required', (string) $response->getBody());
    }

    #[Test]
    public function stepUpRequiredForDropColumnOperation(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users/columns/email',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Step-up authentication required', (string) $response->getBody());
    }

    #[Test]
    public function stepUpRequiredForDropIndexOperation(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(
            method: 'DELETE',
            path: '/admin/schema/tables/users/indexes/idx_email',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Step-up authentication required', (string) $response->getBody());
    }

    #[Test]
    public function allowsCreateOperationWithPermission(): void
    {
        $this->policy->method('evaluate')->willReturn(true);

        // Create is NOT in the step-up list by default
        $middleware = $this->makeMiddleware(requireStepUpFor: ['drop', 'rename', 'drop_column', 'drop_index']);
        $request = $this->makeRequest(
            method: 'POST',
            path: '/admin/schema/tables',
            identity: $this->identity,
        );

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function schemaViewAbstainTreatedAsDenied(): void
    {
        // Policy returns null (abstain) which is !== true
        $this->policy->method('evaluate')->willReturn(null);

        $middleware = $this->makeMiddleware();
        $request = $this->makeRequest(identity: $this->identity);

        $response = $middleware->process($request, $this->nextHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('Schema viewing not permitted', (string) $response->getBody());
    }

    #[Test]
    public function doesNotCallNextWhenDisabled(): void
    {
        $middleware = $this->makeMiddleware(enabled: false);
        $request = $this->makeRequest(identity: $this->identity);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware->process($request, $handler);
    }
}
