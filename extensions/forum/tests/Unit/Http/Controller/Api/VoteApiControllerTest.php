<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
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
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAuthRequest(string $method, string $uri, ?array $body = null): ServerRequest
    {
        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    #[Test]
    public function threadVoteThrowsWhenNotAuthenticated(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $controller = new VoteApiController($voteService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/t1/vote',
        )->withParsedBody(['direction' => 'up']);

        $this->expectException(ForumException::class);

        $controller->threadVote($request, 't1');
    }

    #[Test]
    public function threadVoteWithInvalidBodyReturns400(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $controller = new VoteApiController($voteService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/t1/vote',
        )->withAttribute('identity', $this->makeIdentity());
        // No parsed body

        $response = $controller->threadVote($request, 't1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function threadVoteWithInvalidDirectionReturns422(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $controller = new VoteApiController($voteService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/threads/t1/vote', ['direction' => 'sideways']);

        $response = $controller->threadVote($request, 't1');

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('up', $body['details']['direction']);
    }

    #[Test]
    public function threadVoteUpReturns201(): void
    {
        $now = new DateTimeImmutable();

        $vote = new ThreadVote(
            id: 'vote-1',
            tenantId: null,
            threadId: 'thread-1',
            userId: 'user-1',
            value: VoteDirection::Up,
            createdAt: $now,
        );

        $voteService = $this->createStub(VoteServiceInterface::class);
        $voteService->method('castThreadVote')->willReturn($vote);

        $controller = new VoteApiController($voteService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/threads/thread-1/vote', ['direction' => 'up']);

        $response = $controller->threadVote($request, 'thread-1');

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('vote-1', $body['data']['id']);
        self::assertSame('thread-1', $body['data']['thread_id']);
        self::assertSame(1, $body['data']['direction']);
    }

    #[Test]
    public function postVoteDownReturns201(): void
    {
        $now = new DateTimeImmutable();

        $vote = new PostVote(
            id: 'vote-2',
            tenantId: null,
            postId: 'post-1',
            userId: 'user-1',
            value: VoteDirection::Down,
            createdAt: $now,
        );

        $voteService = $this->createStub(VoteServiceInterface::class);
        $voteService->method('castPostVote')->willReturn($vote);

        $controller = new VoteApiController($voteService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/posts/post-1/vote', ['direction' => 'down']);

        $response = $controller->postVote($request, 'post-1');

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('vote-2', $body['data']['id']);
        self::assertSame('post-1', $body['data']['post_id']);
        self::assertSame(-1, $body['data']['direction']);
    }

    #[Test]
    public function removeThreadVoteReturnsSuccess(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $controller = new VoteApiController($voteService);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/threads/t1/vote',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->removeThreadVote($request, 't1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('t1', $body['data']['thread_id']);
        self::assertSame('removed', $body['data']['status']);
    }

    #[Test]
    public function removePostVoteReturnsSuccess(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $controller = new VoteApiController($voteService);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/p1/vote',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->removePostVote($request, 'p1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('p1', $body['data']['post_id']);
        self::assertSame('removed', $body['data']['status']);
    }

    #[Test]
    public function threadVoteWithServiceExceptionReturns422(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $voteService->method('castThreadVote')->willThrowException(
            ForumException::unauthorized('Already voted'),
        );

        $controller = new VoteApiController($voteService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/threads/t1/vote', ['direction' => 'up']);

        $response = $controller->threadVote($request, 't1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function postVoteWithNullDirectionReturns422(): void
    {
        $voteService = $this->createStub(VoteServiceInterface::class);
        $controller = new VoteApiController($voteService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/posts/p1/vote', ['direction' => null]);

        $response = $controller->postVote($request, 'p1');

        self::assertSame(422, $response->getStatusCode());
    }
}
