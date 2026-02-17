<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\ToolsController;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ToolsController::class)]
final class ToolsControllerTest extends TestCase
{
    #[Test]
    public function export_user_data_returns_data(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $toolsService->method('exportUserData')->willReturn([
            'content' => [['id' => 'c-1', 'title' => 'My Article']],
            'comments' => [],
        ]);

        $controller = new ToolsController(toolsService: $toolsService);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['user_id' => 'user-123'],
        );

        $response = $controller->exportUserData($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('user-123', $body['user_id']);
        self::assertIsArray($body['data']);
    }

    #[Test]
    public function export_user_data_returns_400_when_user_id_missing(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $controller = new ToolsController(toolsService: $toolsService);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: []);

        $response = $controller->exportUserData($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function erase_user_data_returns_success_with_all_counts(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $toolsService->method('eraseUserData')->willReturn([
            'comments_anonymized' => 5,
            'content_anonymized' => 2,
            'reviews_anonymized' => 1,
            'media_anonymized' => 3,
            'customers_redacted' => 1,
            'orders_redacted' => 2,
            'revisions_anonymized' => 4,
            'api_keys_anonymized' => 0,
            'settings_history_anonymized' => 1,
            'form_submissions_deleted' => 7,
            'newsletter_subscribers_deleted' => 1,
        ]);

        $controller = new ToolsController(toolsService: $toolsService);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: [
                'user_id' => 'user-123',
                'reason' => 'GDPR right-to-erasure request from user',
            ],
        );

        $response = $controller->eraseUserData($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('erased', $body['status']);
        self::assertSame(5, $body['comments_anonymized']);
        self::assertSame(2, $body['content_anonymized']);
        self::assertSame(7, $body['form_submissions_deleted']);
        self::assertSame(1, $body['newsletter_subscribers_deleted']);
    }

    #[Test]
    public function erase_user_data_returns_400_when_user_id_missing(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $controller = new ToolsController(toolsService: $toolsService);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'Long enough reason text here'],
        );

        $response = $controller->eraseUserData($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function erase_user_data_returns_400_when_reason_too_short(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $controller = new ToolsController(toolsService: $toolsService);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['user_id' => 'user-123', 'reason' => 'short'],
        );

        $response = $controller->eraseUserData($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function export_user_data_throws_when_unauthenticated(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $controller = new ToolsController(toolsService: $toolsService);

        $this->expectException(AuthenticationException::class);
        $controller->exportUserData($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function export_user_data_throws_when_authorization_denied(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new ToolsController(toolsService: $toolsService, gate: $gate);
        $request = $this->createAuthenticatedRequest(stepUp: true);

        $this->expectException(AuthorizationException::class);
        $controller->exportUserData($request);
    }

    #[Test]
    public function erase_requires_gdpr_erase_permission_not_export(): void
    {
        $toolsService = $this->createStub(ToolsServiceInterface::class);
        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())
            ->method('denies')
            ->with(self::anything(), 'cms.tools.gdpr.erase')
            ->willReturn(true);

        $controller = new ToolsController(toolsService: $toolsService, gate: $gate);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: [
                'user_id' => 'user-123',
                'reason' => 'GDPR right-to-erasure request from user',
            ],
        );

        $this->expectException(AuthorizationException::class);
        $controller->eraseUserData($request);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/tools');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/tools');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
