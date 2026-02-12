<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Http\Controller\Admin\BulkOperationsController;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Http\Message\Response;

use function json_decode;
use function range;
use function strtolower;

use const JSON_THROW_ON_ERROR;

#[CoversClass(BulkOperationsController::class)]
final class BulkOperationsControllerTest extends TestCase
{
    private ContentRepositoryInterface&Stub $contentRepository;

    private TaxonomyServiceInterface&Stub $taxonomyService;

    private BulkOperationsController $controller;

    protected function setUp(): void
    {
        $this->contentRepository = $this->createStub(ContentRepositoryInterface::class);
        $this->taxonomyService = $this->createStub(TaxonomyServiceInterface::class);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $this->controller = new BulkOperationsController(
            $this->contentRepository,
            $this->taxonomyService,
            $gate,
            new CmsConfig(),
        );
    }

    #[Test]
    public function bulk_publish_returns_affected_count(): void
    {
        $this->contentRepository->method('bulkUpdateStatus')->willReturn(3);

        $request = $this->createBulkRequest('publish', ['id-1', 'id-2', 'id-3']);
        $response = $this->controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame(3, $body['affected']);
        self::assertSame('publish', $body['action']);
    }

    #[Test]
    public function bulk_unpublish_uses_draft_status(): void
    {
        $contentRepo = $this->createMock(ContentRepositoryInterface::class);
        $contentRepo->expects(self::once())
            ->method('bulkUpdateStatus')
            ->with(['id-1'], PublishingStatus::Draft, null)
            ->willReturn(1);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = new BulkOperationsController(
            $contentRepo,
            $this->taxonomyService,
            $gate,
            new CmsConfig(),
        );

        $request = $this->createBulkRequest('unpublish', ['id-1']);
        $response = $controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame(1, $body['affected']);
        self::assertSame('unpublish', $body['action']);
    }

    #[Test]
    public function bulk_archive_uses_archived_status(): void
    {
        $this->contentRepository->method('bulkUpdateStatus')->willReturn(2);

        $request = $this->createBulkRequest('archive', ['id-1', 'id-2']);
        $response = $this->controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame(2, $body['affected']);
        self::assertSame('archive', $body['action']);
    }

    #[Test]
    public function bulk_delete_returns_affected_count(): void
    {
        $this->contentRepository->method('bulkDelete')->willReturn(5);

        $request = $this->createBulkRequest('delete', ['id-1', 'id-2', 'id-3', 'id-4', 'id-5']);
        $response = $this->controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame(5, $body['affected']);
        self::assertSame('delete', $body['action']);
    }

    #[Test]
    public function bulk_tag_returns_affected_count(): void
    {
        $request = $this->createBulkRequest('tag', ['id-1', 'id-2'], ['term_id' => 'term-abc']);
        $response = $this->controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame(2, $body['affected']);
        self::assertSame('tag', $body['action']);
    }

    #[Test]
    public function bulk_untag_returns_affected_count(): void
    {
        $request = $this->createBulkRequest('untag', ['id-1'], ['term_id' => 'term-xyz']);
        $response = $this->controller->execute($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame(1, $body['affected']);
        self::assertSame('untag', $body['action']);
    }

    #[Test]
    public function tag_without_term_id_returns_422(): void
    {
        $request = $this->createBulkRequest('tag', ['id-1']);
        $response = $this->controller->execute($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertIsString($body['error']);
        self::assertStringContainsString('term_id', $body['error']);
    }

    #[Test]
    public function untag_without_term_id_returns_422(): void
    {
        $request = $this->createBulkRequest('untag', ['id-1']);
        $response = $this->controller->execute($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertIsString($body['error']);
        self::assertStringContainsString('term_id', $body['error']);
    }

    #[Test]
    public function exceeding_max_ids_returns_422(): void
    {
        $ids = [];

        foreach (range(1, 101) as $i) {
            $ids[] = 'id-' . $i;
        }

        $request = $this->createBulkRequest('publish', $ids);
        $response = $this->controller->execute($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertIsString($body['error']);
        self::assertStringContainsString('100', $body['error']);
    }

    #[Test]
    public function empty_ids_returns_422(): void
    {
        $request = $this->createBulkRequest('publish', []);
        $response = $this->controller->execute($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertIsString($body['error']);
        self::assertStringContainsString('at least one', $body['error']);
    }

    #[Test]
    public function invalid_action_returns_400(): void
    {
        $request = $this->createBulkRequest('destroy', ['id-1']);
        $response = $this->controller->execute($request);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Invalid bulk action', $body['error']);
        self::assertSame(['publish', 'unpublish', 'archive', 'delete', 'tag', 'untag'], $body['valid_actions']);
    }

    #[Test]
    public function non_array_ids_returns_422(): void
    {
        $request = $this->createBulkRequest('publish', null);
        $response = $this->controller->execute($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertIsString($body['error']);
        self::assertStringContainsString('ids must be an array', $body['error']);
    }

    /**
     * @param list<string>|null $ids
     * @param array<string, mixed> $extra
     */
    private function createBulkRequest(string $action, ?array $ids, array $extra = []): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('user-001');

        $parsedBody = $extra;

        if ($ids !== null) {
            $parsedBody['ids'] = $ids;
        } else {
            $parsedBody['ids'] = 'not-an-array';
        }

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match (strtolower($name)) {
                'accept' => 'application/json',
                default => '',
            },
        );
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null) => match ($name) {
                'identity' => $identity,
                'action' => $action,
                default => $default,
            },
        );
        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
