<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Graphql\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Graphql\Execution\GraphqlExecutor;
use Pulsar\Extension\Graphql\Http\GraphqlController;
use Pulsar\Extension\Graphql\Resolver\ContentResolver;
use Pulsar\Extension\Graphql\Resolver\MediaResolver;
use Pulsar\Extension\Graphql\Resolver\TaxonomyResolver;
use Pulsar\Extension\Graphql\Schema\SchemaBuilder;

use function json_decode;
use function str_repeat;
use function strlen;

use const JSON_THROW_ON_ERROR;

#[CoversClass(GraphqlController::class)]
final class GraphqlControllerTest extends TestCase
{
    private GraphqlController $controller;
    private ContentRepositoryInterface&Stub $contentRepo;

    protected function setUp(): void
    {
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $blockRepo = $this->createStub(ContentBlockRepositoryInterface::class);
        $taxonomyRepo = $this->createStub(TaxonomyRepositoryInterface::class);
        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);

        $schema = new SchemaBuilder()->build();
        $contentResolver = new ContentResolver($this->contentRepo, $translationRepo, $blockRepo);
        $taxonomyResolver = new TaxonomyResolver($taxonomyRepo);
        $mediaResolver = new MediaResolver($mediaRepo);
        $executor = new GraphqlExecutor($schema, $contentResolver, $taxonomyResolver, $mediaResolver);

        $this->controller = new GraphqlController($executor, $schema);
    }

    #[Test]
    public function execute_returns_400_for_empty_body(): void
    {
        $request = $this->createRequest('');

        $response = $this->controller->execute($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertNotEmpty($body['errors']);
    }

    #[Test]
    public function execute_returns_400_for_invalid_json(): void
    {
        $request = $this->createRequest('not json');

        $response = $this->controller->execute($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function execute_returns_400_for_missing_query(): void
    {
        $request = $this->createRequest('{"variables": {}}');

        $response = $this->controller->execute($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function execute_returns_200_with_data_for_valid_query(): void
    {
        $now = new DateTimeImmutable('2025-01-15T10:00:00+00:00');
        $content = new Content(
            id: 'c-001',
            tenantId: null,
            contentType: ContentType::Article,
            authorId: 'u-001',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $now,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Open,
            dataClassification: DataClassification::Public,
        );

        $this->contentRepo->method('findById')->willReturn($content);

        $json = json_encode([
            'query' => '{ content(id: "c-001") { id contentType } }',
        ], JSON_THROW_ON_ERROR);

        $request = $this->createRequest($json);
        $response = $this->controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponse($response);
        self::assertSame([], $body['errors']);
        self::assertIsArray($body['data']);
        self::assertIsArray($body['data']['content']);
        self::assertSame('c-001', $body['data']['content']['id']);
        self::assertSame('article', $body['data']['content']['contentType']);
    }

    #[Test]
    public function execute_returns_json_content_type(): void
    {
        $this->contentRepo->method('findById')->willReturn(null);

        $json = json_encode([
            'query' => '{ content(id: "x") { id } }',
        ], JSON_THROW_ON_ERROR);

        $request = $this->createRequest($json);
        $response = $this->controller->execute($request);

        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function execute_returns_413_for_oversized_body(): void
    {
        // 64KB = 65,536 bytes; generate a body that exceeds this
        $oversizedQuery = str_repeat('a', 65_537);
        $request = $this->createRequest($oversizedQuery);

        $response = $this->controller->execute($request);

        self::assertSame(413, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertNull($body['data']);
        self::assertNotEmpty($body['errors']);
        self::assertIsArray($body['errors']);
        self::assertIsArray($body['errors'][0]);
        self::assertIsString($body['errors'][0]['message']);
        self::assertStringContainsString('maximum allowed size', $body['errors'][0]['message']);
    }

    #[Test]
    public function execute_accepts_body_at_exact_limit(): void
    {
        // Build a valid JSON body that is exactly 65,536 bytes
        // The JSON envelope {"query":"..."} uses 12 bytes for structure
        $padding = str_repeat(' ', 65_536 - 12);
        $jsonBody = '{"query":"' . $padding . '"}';
        self::assertSame(65_536, strlen($jsonBody));

        $request = $this->createRequest($jsonBody);
        $response = $this->controller->execute($request);

        // Should NOT be 413 — must pass the size check
        self::assertNotSame(413, $response->getStatusCode());
    }

    #[Test]
    public function introspect_returns_schema(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->controller->introspect();

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponse($response);
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);
        self::assertArrayHasKey('__schema', $body['data']);
        self::assertIsArray($body['data']['__schema']);
        self::assertIsArray($body['data']['__schema']['queryType']);
        self::assertSame('Query', $body['data']['__schema']['queryType']['name']);
    }

    private function createRequest(string $body): ServerRequestInterface&Stub
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(\Pulsar\Http\Message\Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
