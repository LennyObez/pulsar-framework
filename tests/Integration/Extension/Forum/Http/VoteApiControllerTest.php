<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\VoteApiController;
use Pulsar\Extension\Forum\Service\VoteServiceInterface;
use Pulsar\Extension\Forum\Vote\PostVote;
use Pulsar\Extension\Forum\Vote\ThreadVote;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(VoteApiController::class)]
final class VoteApiControllerTest extends TestCase
{
    private VoteServiceInterface&Stub $voteService;
    private VoteApiController $controller;

    protected function setUp(): void
    {
        $this->voteService = $this->createStub(VoteServiceInterface::class);
        $this->controller = new VoteApiController($this->voteService);
    }

    #[Test]
    public function threadVoteRequiresAuthentication(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/vote',
            parsedBody: ['direction' => 'up'],
        );

        $this->expectException(ForumException::class);
        $this->controller->threadVote($request, 'thread-001');
    }

    #[Test]
    public function threadVoteReturns422ForInvalidDirection(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/vote',
            parsedBody: ['direction' => 'sideways'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->threadVote($request, 'thread-001');

        self::assertSame(422, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['details']);
        self::assertArrayHasKey('direction', $data['details']);
    }

    #[Test]
    public function threadVoteReturns400ForInvalidBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/vote',
            parsedBody: null,
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->threadVote($request, 'thread-001');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function threadVoteReturns201OnUpvote(): void
    {
        $vote = new ThreadVote(
            id: 'vote-001',
            tenantId: null,
            userId: 'user-001',
            threadId: 'thread-001',
            value: VoteDirection::Up,
            createdAt: new DateTimeImmutable(),
        );
        $this->voteService->method('castThreadVote')->willReturn($vote);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/vote',
            parsedBody: ['direction' => 'up'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->threadVote($request, 'thread-001');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('vote-001', $data['data']['id']);
        self::assertSame('thread-001', $data['data']['thread_id']);
        self::assertSame(1, $data['data']['direction']);
    }

    #[Test]
    public function threadVoteReturns201OnDownvote(): void
    {
        $vote = new ThreadVote(
            id: 'vote-002',
            tenantId: null,
            userId: 'user-001',
            threadId: 'thread-001',
            value: VoteDirection::Down,
            createdAt: new DateTimeImmutable(),
        );
        $this->voteService->method('castThreadVote')->willReturn($vote);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/vote',
            parsedBody: ['direction' => 'down'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->threadVote($request, 'thread-001');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame(-1, $data['data']['direction']);
    }

    #[Test]
    public function postVoteReturns201OnSuccess(): void
    {
        $vote = new PostVote(
            id: 'pv-001',
            tenantId: null,
            userId: 'user-001',
            postId: 'post-001',
            value: VoteDirection::Up,
            createdAt: new DateTimeImmutable(),
        );
        $this->voteService->method('castPostVote')->willReturn($vote);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-001/vote',
            parsedBody: ['direction' => 'up'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->postVote($request, 'post-001');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('pv-001', $data['data']['id']);
        self::assertSame('post-001', $data['data']['post_id']);
    }

    #[Test]
    public function postVoteReturns422ForMissingDirection(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-001/vote',
            parsedBody: [],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->postVote($request, 'post-001');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function removeThreadVoteReturnsSuccess(): void
    {
        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/threads/thread-001/vote',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->removeThreadVote($request, 'thread-001');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('removed', $data['data']['status']);
        self::assertSame('thread-001', $data['data']['thread_id']);
    }

    #[Test]
    public function removePostVoteReturnsSuccess(): void
    {
        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/post-001/vote',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->removePostVote($request, 'post-001');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('removed', $data['data']['status']);
        self::assertSame('post-001', $data['data']['post_id']);
    }

    private function createIdentity(string $id): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('displayName')->willReturn('Test User');
        $identity->method('roles')->willReturn([]);
        $identity->method('hasRole')->willReturn(false);
        $identity->method('twoFactorStatus')->willReturn(TwoFactorStatus::Disabled);
        $identity->method('attributes')->willReturn([]);

        return $identity;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
