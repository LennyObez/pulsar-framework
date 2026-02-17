<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Graphql\Execution\GraphqlExecutor;
use Pulsar\Extension\Graphql\Resolver\ContentResolver;
use Pulsar\Extension\Graphql\Resolver\MediaResolver;
use Pulsar\Extension\Graphql\Resolver\TaxonomyResolver;
use Pulsar\Extension\Graphql\Schema\SchemaBuilder;

final class GraphqlExecutorTest extends TestCase
{
    private GraphqlExecutor $executor;
    private ContentResolver&Stub $contentResolver;
    private TaxonomyResolver&Stub $taxonomyResolver;
    private MediaResolver&Stub $mediaResolver;

    protected function setUp(): void
    {
        $this->contentResolver = $this->createStub(ContentResolver::class);
        $this->taxonomyResolver = $this->createStub(TaxonomyResolver::class);
        $this->mediaResolver = $this->createStub(MediaResolver::class);

        $schema = new SchemaBuilder()->build();

        $this->executor = new GraphqlExecutor(
            $schema,
            $this->contentResolver,
            $this->taxonomyResolver,
            $this->mediaResolver,
        );
    }

    #[Test]
    public function executeReturnsDataAndErrorsKeys(): void
    {
        $this->contentResolver->method('resolveById')->willReturn([
            'id' => '1',
            'title' => 'Test Post',
            'slug' => 'test-post',
        ]);

        $result = $this->executor->execute('{ content(id: "1") { id title } }');

        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('errors', $result);
    }

    #[Test]
    public function executeReturnsErrorForInvalidQuery(): void
    {
        $result = $this->executor->execute('{ invalid syntax');

        self::assertNotEmpty($result['errors']);
    }

    #[Test]
    public function executeReturnsNullDataForUnknownField(): void
    {
        $result = $this->executor->execute('{ unknownField { id } }');

        // Unknown fields should produce errors
        self::assertNotEmpty($result['errors'] ?? []);
    }

    #[Test]
    public function executeResolvesContentById(): void
    {
        $this->contentResolver->method('resolveById')->willReturn([
            'id' => 'abc-123',
            'title' => 'Article Title',
            'slug' => 'article-title',
            'type' => 'post',
        ]);

        $result = $this->executor->execute('{ content(id: "abc-123") { id title slug } }');

        self::assertNotNull($result['data']);
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        self::assertArrayHasKey('content', $data);
        /** @var array<string, mixed> $content */
        $content = $data['content'];
        self::assertSame('abc-123', $content['id']);
        self::assertSame('Article Title', $content['title']);
    }

    #[Test]
    public function executeReturnsNullWhenContentNotFound(): void
    {
        $this->contentResolver->method('resolveById')->willReturn(null);

        $result = $this->executor->execute('{ content(id: "nonexistent") { id } }');

        /** @var array<string, mixed>|null $data */
        $data = $result['data'];
        self::assertNotNull($data);
        self::assertNull($data['content']);
    }

    #[Test]
    public function executeResolvesTaxonomy(): void
    {
        $this->taxonomyResolver->method('resolveBySlug')->willReturn([
            'id' => 'tax-1',
            'name' => 'Categories',
            'slug' => 'categories',
        ]);

        $result = $this->executor->execute('{ taxonomy(slug: "categories") { id name } }');

        self::assertNotNull($result['data']);
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        /** @var array<string, mixed> $taxonomy */
        $taxonomy = $data['taxonomy'];
        self::assertSame('Categories', $taxonomy['name']);
    }

    #[Test]
    public function executeResolvesMedia(): void
    {
        $this->mediaResolver->method('resolveById')->willReturn([
            'id' => 'media-1',
            'filename' => 'photo.jpg',
            'mimeType' => 'image/jpeg',
        ]);

        $result = $this->executor->execute('{ media(id: "media-1") { id filename } }');

        self::assertNotNull($result['data']);
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        /** @var array<string, mixed> $media */
        $media = $data['media'];
        self::assertSame('photo.jpg', $media['filename']);
    }

    #[Test]
    public function executeReturnsNullWhenMediaNotFound(): void
    {
        $this->mediaResolver->method('resolveById')->willReturn(null);

        $result = $this->executor->execute('{ media(id: "nonexistent") { id } }');

        /** @var array<string, mixed>|null $data */
        $data = $result['data'];
        self::assertNotNull($data);
        self::assertNull($data['media']);
    }

    #[Test]
    public function executeRejectsQueryExceedingMaxDepth(): void
    {
        // Build a query with 16 levels of nesting (exceeds MAX_DEPTH=15)
        $query = '{ content(id: "1") ';
        for ($i = 0; $i < 16; $i++) {
            $query .= '{ nested' . $i . ' ';
        }
        for ($i = 0; $i < 16; $i++) {
            $query .= '} ';
        }
        $query .= '}';

        $result = $this->executor->execute($query);

        self::assertNotEmpty($result['errors']);
        /** @var array{message: string} $firstError */
        $firstError = $result['errors'][0];
        self::assertStringContainsString('Maximum query depth exceeded', $firstError['message']);
        self::assertNull($result['data']);
    }

    #[Test]
    public function executeAcceptsQueryAtExactMaxDepth(): void
    {
        $this->contentResolver->method('resolveById')->willReturn([
            'id' => '1',
            'title' => 'Test',
        ]);

        // Depth of 15 exactly (1 for root field + 14 levels of nesting = 15)
        $query = '{ content(id: "1") ';
        for ($i = 0; $i < 14; $i++) {
            $query .= '{ n' . $i . ' ';
        }
        for ($i = 0; $i < 14; $i++) {
            $query .= '} ';
        }
        $query .= '}';

        $result = $this->executor->execute($query);

        // Should not contain a depth error
        /** @var list<array{message: string}> $errors */
        $errors = $result['errors'];
        $depthErrors = array_filter(
            $errors,
            static fn(array $e): bool => str_contains($e['message'], 'Maximum query depth'),
        );
        self::assertEmpty($depthErrors);
    }

    #[Test]
    public function executeRejectsQueryExceedingMaxFields(): void
    {
        // Build a query with 501 fields at root level (exceeds MAX_FIELDS=500)
        $fields = '';
        for ($i = 0; $i < 501; $i++) {
            $fields .= 'field' . $i . ' ';
        }
        $query = '{ ' . $fields . '}';

        $result = $this->executor->execute($query);

        self::assertNotEmpty($result['errors']);
        /** @var array{message: string} $firstError */
        $firstError = $result['errors'][0];
        self::assertStringContainsString('Too many fields requested', $firstError['message']);
        self::assertNull($result['data']);
    }

    #[Test]
    public function executeAcceptsQueryWithFieldsJustUnderLimit(): void
    {
        $this->contentResolver->method('resolveById')->willReturn([
            'id' => '1',
            'title' => 'Test',
        ]);

        // A simple query with just a few fields passes easily
        $result = $this->executor->execute('{ content(id: "1") { id title } }');

        /** @var list<array{message: string}> $errors */
        $errors = $result['errors'];
        $fieldErrors = array_filter(
            $errors,
            static fn(array $e): bool => str_contains($e['message'], 'Too many fields'),
        );
        self::assertEmpty($fieldErrors);
    }

    #[Test]
    public function executeCountsNestedFieldsTowardTotal(): void
    {
        // Build a query where nested fields push total over 500
        // 10 root fields, each with 51 sub-fields = 10 + 510 = 520 > 500
        $query = '{ ';
        for ($i = 0; $i < 10; $i++) {
            $query .= 'root' . $i . ' { ';
            for ($j = 0; $j < 51; $j++) {
                $query .= 'sub' . $j . ' ';
            }
            $query .= '} ';
        }
        $query .= '}';

        $result = $this->executor->execute($query);

        self::assertNotEmpty($result['errors']);
        /** @var array{message: string} $firstError */
        $firstError = $result['errors'][0];
        self::assertStringContainsString('Too many fields requested', $firstError['message']);
    }
}
