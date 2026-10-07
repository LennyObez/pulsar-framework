<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Forms\FormSubmission;
use Pulsar\Extension\Cms\Forms\FormSubmissionRepositoryInterface;
use Pulsar\Extension\Cms\Forms\FormSubmissionServiceInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\FormSubmissionController;
use RuntimeException;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FormSubmissionController::class)]
final class FormSubmissionControllerTest extends TestCase
{
    #[Test]
    public function index_returns_submissions_with_pagination(): void
    {
        $submission = $this->createSubmission('sub-1');

        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);
        $repo->method('findAll')->willReturn([$submission]);
        $repo->method('countUnread')->willReturn(3);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $submissions */
        $submissions = $body['submissions'];
        self::assertCount(1, $submissions);
        self::assertSame('sub-1', $submissions[0]['id']);
        self::assertFalse($submissions[0]['is_spam']);

        self::assertSame(3, $body['unread_count']);
        self::assertSame('all', $body['filter']);
    }

    #[Test]
    public function show_returns_submission_detail(): void
    {
        $submission = $this->createSubmission('sub-1', isRead: false);

        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);
        $repo->method('findById')->willReturn($submission);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'sub-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $sub */
        $sub = $body['submission'];
        self::assertSame('sub-1', $sub['id']);
        self::assertTrue($sub['is_read']);
    }

    #[Test]
    public function show_returns_404_when_not_found(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function mark_as_read_returns_success(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->markAsRead($request, 'sub-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['is_read']);
    }

    #[Test]
    public function mark_as_read_returns_404_on_exception(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $formService->method('markAsRead')->willThrowException(new RuntimeException('Not found'));

        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->markAsRead($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function mark_as_spam_returns_success(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'reason' => 'Obvious spam content',
        ]);

        $response = $controller->markAsSpam($request, 'sub-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['is_spam']);
    }

    #[Test]
    public function export_returns_csv_response(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $formService->method('exportSubmissions')->willReturn("name,email\nJohn,john@example.com\n");

        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest(queryParams: ['content_id' => 'content-1']);

        $response = $controller->export($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function export_returns_400_without_content_id(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->export($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function bulk_delete_removes_found_submissions(): void
    {
        $submission = $this->createSubmission('sub-1');

        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(
            static fn(string $id): ?FormSubmission => match ($id) {
                'sub-1' => $submission,
                default => null,
            },
        );

        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'ids' => ['sub-1', 'nonexistent'],
        ]);

        $response = $controller->bulkDelete($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['deleted']);
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);
        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $controller = new FormSubmissionController(repository: $repo, formService: $formService);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(FormSubmissionRepositoryInterface::class);
        $formService = $this->createStub(FormSubmissionServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new FormSubmissionController(repository: $repo, formService: $formService, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createSubmission(string $id, bool $isRead = false): FormSubmission
    {
        return new FormSubmission(
            id: $id,
            formBlockId: 'form-block-1',
            contentId: 'content-1',
            tenantId: null,
            data: ['name' => 'John Doe', 'email' => 'john@example.com', '_token' => 'csrf'],
            ipHash: 'hash_ip',
            userAgentHash: 'hash_ua',
            submittedAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
            evidenceHash: 'evidence_hash',
            isRead: $isRead,
            isSpam: false,
            spamScore: 0.1,
            spamReason: null,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        ?array $parsedBody = null,
        array $queryParams = [],
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/forms');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
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
        $uri->method('getPath')->willReturn('/admin/forms');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
