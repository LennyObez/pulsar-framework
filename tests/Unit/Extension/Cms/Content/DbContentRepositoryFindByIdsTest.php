<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRepository;

#[CoversClass(DbContentRepository::class)]
final class DbContentRepositoryFindByIdsTest extends TestCase
{
    private ConnectionInterface&MockObject $connection;
    private DbContentRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->repository = new DbContentRepository($this->connection, null);
    }

    #[Test]
    public function empty_ids_returns_empty_array_without_querying(): void
    {
        $this->connection->expects(self::never())->method('query');

        $result = $this->repository->findByIds([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function valid_uuids_are_passed_to_connection(): void
    {
        $uuid1 = '01234567-89ab-cdef-0123-456789abcdef';
        $uuid2 = 'ABCDEF01-2345-6789-ABCD-EF0123456789';

        $this->connection->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static function (array $bindings) use ($uuid1, $uuid2): bool {
                    return $bindings['ids'] === '{' . $uuid1 . ',' . $uuid2 . '}';
                }),
            )
            ->willReturn(new Result([
                self::makeContentRow($uuid1),
                self::makeContentRow($uuid2),
            ]));

        $result = $this->repository->findByIds([$uuid1, $uuid2]);

        self::assertCount(2, $result);
        self::assertArrayHasKey($uuid1, $result);
        self::assertArrayHasKey($uuid2, $result);
    }

    #[Test]
    #[DataProvider('maliciousIdProvider')]
    public function rejects_malicious_id(mixed $maliciousId): void
    {
        $this->connection->expects(self::never())->method('query');

        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (testing runtime validation of non-string inputs) */
        $this->repository->findByIds([$maliciousId]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function maliciousIdProvider(): iterable
    {
        yield 'SQL injection via closing brace' => ["'}; DROP TABLE cms_contents;--"];
        yield 'SQL injection via union' => ["00000000-0000-0000-0000-000000000000' UNION SELECT * FROM users--"];
        yield 'empty string' => [''];
        yield 'too short' => ['not-a-uuid'];
        yield 'uuid without hyphens' => ['0123456789abcdef0123456789abcdef'];
        yield 'uuid with extra chars' => ['01234567-89ab-cdef-0123-456789abcdef-extra'];
        yield 'integer value' => [42];
        yield 'null value' => [null];
        yield 'boolean value' => [true];
        yield 'array value' => [['nested']];
    }

    #[Test]
    public function rejects_when_one_id_in_batch_is_invalid(): void
    {
        $validUuid = '01234567-89ab-cdef-0123-456789abcdef';
        $invalidId = 'not-a-uuid';

        $this->connection->expects(self::never())->method('query');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID in findByIds: not-a-uuid');

        $this->repository->findByIds([$validUuid, $invalidId]);
    }

    #[Test]
    public function exception_message_includes_type_for_non_string(): void
    {
        $this->connection->expects(self::never())->method('query');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID in findByIds: int');

        /** @phpstan-ignore argument.type (testing runtime validation of non-string inputs) */
        $this->repository->findByIds([123]);
    }

    private static function makeContentRow(string $id): Row
    {
        return new Row([
            'id' => $id,
            'tenant_id' => null,
            'content_type' => 'article',
            'author_id' => '00000000-0000-0000-0000-000000000001',
            'status' => 'draft',
            'scheduled_publish_at' => null,
            'scheduled_unpublish_at' => null,
            'published_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
            'deleted_at' => null,
            'template' => null,
            'parent_id' => null,
            'sort_order' => 0,
            'comment_policy' => 'open',
            'data_classification' => 'public',
            'version' => 1,
        ]);
    }
}
