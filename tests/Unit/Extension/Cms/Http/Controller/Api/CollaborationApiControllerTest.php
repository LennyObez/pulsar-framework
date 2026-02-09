<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationSession;
use Pulsar\Extension\Cms\Collaboration\CrdtDocument;
use Pulsar\Extension\Cms\Http\Controller\Api\CollaborationApiController;
use Pulsar\Extension\Cms\Internal\Collaboration\CollaborationService;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CollaborationApiController::class)]
final class CollaborationApiControllerTest extends TestCase
{
    private const string CONTENT_ID = 'content-001';

    private CollaborationRepositoryInterface&Stub $repository;
    private CollaborationService $service;
    private CollaborationApiController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(CollaborationRepositoryInterface::class);
        $this->service = new CollaborationService($this->repository);
        $this->controller = new CollaborationApiController($this->service);
    }

    #[Test]
    public function get_state_returns_document_and_sessions(): void
    {
        $now = new DateTimeImmutable();
        $doc = new CrdtDocument(self::CONTENT_ID, 'abc123==', 3, $now);
        $session = new CollaborationSession(
            's1',
            self::CONTENT_ID,
            'u1',
            'Alice',
            '5:10',
            null,
            $now,
            $now,
        );

        $this->repository->method('getDocument')->willReturn($doc);
        $this->repository->method('getActiveSessions')->willReturn([$session]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/collaboration/' . self::CONTENT_ID . '/state',
            attributes: ['contentId' => self::CONTENT_ID],
        );

        $response = $this->controller->getState($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{document: array<string, mixed>, sessions: list<array<string, mixed>>} $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertNotNull($body['document']);
        self::assertSame(self::CONTENT_ID, $body['document']['content_id']);
        self::assertSame(3, $body['document']['version']);
        self::assertSame('abc123==', $body['document']['state_vector']);
        self::assertCount(1, $body['sessions']);
        self::assertSame('Alice', $body['sessions'][0]['user_name']);
    }

    #[Test]
    public function get_state_returns_null_document_when_not_found(): void
    {
        $this->repository->method('getDocument')->willReturn(null);
        $this->repository->method('getActiveSessions')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/collaboration/' . self::CONTENT_ID . '/state',
            attributes: ['contentId' => self::CONTENT_ID],
        );

        $response = $this->controller->getState($request);

        /** @var array{document: null, sessions: list<mixed>} $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertNull($body['document']);
        self::assertSame([], $body['sessions']);
    }

    #[Test]
    public function join_creates_session_and_returns_201(): void
    {
        $this->repository->method('getDocument')->willReturn(null);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/collaboration/' . self::CONTENT_ID . '/join',
            attributes: ['contentId' => self::CONTENT_ID],
            body: json_encode(['user_id' => 'u1', 'user_name' => 'Alice'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->join($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array{session_id: string, content_id: string, user_id: string, user_name: string} $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(self::CONTENT_ID, $body['content_id']);
        self::assertSame('u1', $body['user_id']);
        self::assertSame('Alice', $body['user_name']);
        self::assertNotEmpty($body['session_id']);
    }

    #[Test]
    public function apply_update_returns_updated_version(): void
    {
        $existing = new CrdtDocument(self::CONTENT_ID, 'old', 5, new DateTimeImmutable());
        $this->repository->method('getDocument')->willReturn($existing);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/collaboration/' . self::CONTENT_ID . '/update',
            attributes: ['contentId' => self::CONTENT_ID],
            body: json_encode(['update' => 'new-state', 'user_id' => 'u1'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->applyUpdate($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{content_id: string, version: int} $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(self::CONTENT_ID, $body['content_id']);
        self::assertSame(6, $body['version']);
    }

    #[Test]
    public function leave_returns_ok(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/collaboration/' . self::CONTENT_ID . '/leave',
            attributes: ['contentId' => self::CONTENT_ID],
            body: json_encode(['session_id' => 's1'], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->leave($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{status: string} $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function awareness_returns_ok(): void
    {
        $this->repository->method('getActiveSessions')->willReturn([]);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/collaboration/' . self::CONTENT_ID . '/awareness',
            attributes: ['contentId' => self::CONTENT_ID],
            body: json_encode([
                'session_id' => 's1',
                'cursor_position' => '10:5',
                'selection_range' => null,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->awareness($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{status: string} $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
    }
}
