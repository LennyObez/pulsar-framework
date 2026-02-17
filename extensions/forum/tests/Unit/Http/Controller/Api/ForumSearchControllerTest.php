<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Http\Controller\Api\ForumSearchController;
use Pulsar\Extension\Forum\Service\ForumSearchServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ForumSearchController::class)]
final class ForumSearchControllerTest extends TestCase
{
    private function makeController(?ForumSearchServiceInterface $searchService = null): ForumSearchController
    {
        return new ForumSearchController(
            searchService: $searchService ?? $this->createStub(ForumSearchServiceInterface::class),
            config: new ForumConfig(),
        );
    }

    private function makeRequest(array $queryParams = []): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/search',
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        );
    }

    #[Test]
    public function emptyQueryReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->search($this->makeRequest());

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
        self::assertSame('Search query is required', $body['details']['q']);
    }

    #[Test]
    public function whitespaceQueryReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->search($this->makeRequest(['q' => '   ']));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function validQueryReturnsResults(): void
    {
        $searchService = $this->createStub(ForumSearchServiceInterface::class);
        $searchService->method('search')->willReturn(new PaginationResult(
            items: [['id' => 't-1', 'title' => 'PHP Tips']],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = $this->makeController($searchService);

        $response = $controller->search($this->makeRequest(['q' => 'PHP']));

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('PHP', $body['query']);
        self::assertArrayHasKey('pagination', $body);
        self::assertArrayHasKey('filters', $body);
    }

    #[Test]
    public function filtersArePassedThrough(): void
    {
        $searchService = $this->createStub(ForumSearchServiceInterface::class);
        $searchService->method('search')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
        ));

        $controller = $this->makeController($searchService);

        $response = $controller->search($this->makeRequest([
            'q' => 'test',
            'category' => 'cat-1',
            'author' => 'user-1',
            'tag' => 'php',
            'solved' => 'true',
            'from' => '2025-01-01',
            'to' => '2025-12-31',
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cat-1', $body['filters']['category']);
        self::assertSame('user-1', $body['filters']['author']);
        self::assertSame('php', $body['filters']['tag']);
        self::assertTrue($body['filters']['solved']);
        self::assertNotNull($body['filters']['from']);
        self::assertNotNull($body['filters']['to']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function solvedFilterProvider(): iterable
    {
        yield 'true string' => ['true', true];
        yield '1 string' => ['1', true];
        yield 'false string' => ['false', false];
        yield '0 string' => ['0', false];
    }

    #[Test]
    #[DataProvider('solvedFilterProvider')]
    public function solvedFilterIsParsedCorrectly(string $input, bool $expected): void
    {
        $searchService = $this->createStub(ForumSearchServiceInterface::class);
        $searchService->method('search')->willReturn(new PaginationResult([], 0, false, 25));

        $controller = $this->makeController($searchService);

        $response = $controller->search($this->makeRequest(['q' => 'test', 'solved' => $input]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($expected, $body['filters']['solved']);
    }

    #[Test]
    public function invalidDateFilterIsIgnored(): void
    {
        $searchService = $this->createStub(ForumSearchServiceInterface::class);
        $searchService->method('search')->willReturn(new PaginationResult([], 0, false, 25));

        $controller = $this->makeController($searchService);

        $response = $controller->search($this->makeRequest([
            'q' => 'test',
            'from' => 'not-a-date',
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertNull($body['filters']['from']);
    }

    #[Test]
    public function emptyFilterValuesAreNull(): void
    {
        $searchService = $this->createStub(ForumSearchServiceInterface::class);
        $searchService->method('search')->willReturn(new PaginationResult([], 0, false, 25));

        $controller = $this->makeController($searchService);

        $response = $controller->search($this->makeRequest([
            'q' => 'test',
            'category' => '',
            'author' => '',
            'tag' => '',
        ]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertNull($body['filters']['category']);
        self::assertNull($body['filters']['author']);
        self::assertNull($body['filters']['tag']);
    }
}
