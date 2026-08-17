<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Format\ResponseContext;
use Pulsar\Api\OpenApi\SecuritySchemeType;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationMeta;
use Pulsar\Api\Resource\RedactionStrategy;
use Pulsar\Api\Security\FieldAuthorizationResult;

#[CoversClass(ResponseContext::class)]
final class ApiValueObjectTest extends TestCase
{
    // ── RedactionStrategy ───────────────────────────────────────────────

    #[Test]
    public function redactionStrategyHasFourCases(): void
    {
        self::assertCount(4, RedactionStrategy::cases());
    }

    #[Test]
    #[DataProvider('redactionStrategyProvider')]
    public function redactionStrategyBackedValues(RedactionStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->value);
    }

    /**
     * @return iterable<string, array{RedactionStrategy, string}>
     */
    public static function redactionStrategyProvider(): iterable
    {
        yield 'Mask' => [RedactionStrategy::Mask, 'mask'];
        yield 'Truncate' => [RedactionStrategy::Truncate, 'truncate'];
        yield 'Hash' => [RedactionStrategy::Hash, 'hash'];
        yield 'Omit' => [RedactionStrategy::Omit, 'omit'];
    }

    #[Test]
    public function redactionStrategyFromBackedValue(): void
    {
        self::assertSame(RedactionStrategy::Mask, RedactionStrategy::from('mask'));
        self::assertSame(RedactionStrategy::Omit, RedactionStrategy::from('omit'));
    }

    // ── SecuritySchemeType ──────────────────────────────────────────────

    #[Test]
    public function securitySchemeTypeHasFourCases(): void
    {
        self::assertCount(4, SecuritySchemeType::cases());
    }

    #[Test]
    #[DataProvider('securitySchemeTypeProvider')]
    public function securitySchemeTypeBackedValues(SecuritySchemeType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{SecuritySchemeType, string}>
     */
    public static function securitySchemeTypeProvider(): iterable
    {
        yield 'Http' => [SecuritySchemeType::Http, 'http'];
        yield 'ApiKey' => [SecuritySchemeType::ApiKey, 'apiKey'];
        yield 'OAuth2' => [SecuritySchemeType::OAuth2, 'oauth2'];
        yield 'OpenIdConnect' => [SecuritySchemeType::OpenIdConnect, 'openIdConnect'];
    }

    #[Test]
    public function securitySchemeTypeFromBackedValue(): void
    {
        self::assertSame(SecuritySchemeType::ApiKey, SecuritySchemeType::from('apiKey'));
        self::assertSame(SecuritySchemeType::OpenIdConnect, SecuritySchemeType::from('openIdConnect'));
    }

    // ── FieldAuthorizationResult ────────────────────────────────────────

    #[Test]
    public function fieldAuthorizationResultHasThreeCases(): void
    {
        self::assertCount(3, FieldAuthorizationResult::cases());
    }

    #[Test]
    public function fieldAuthorizationResultCaseNames(): void
    {
        $names = array_map(
            static fn(FieldAuthorizationResult $r) => $r->name,
            FieldAuthorizationResult::cases(),
        );

        self::assertContains('Allowed', $names);
        self::assertContains('Redacted', $names);
        self::assertContains('Denied', $names);
    }

    #[Test]
    public function fieldAuthorizationResultCasesAreDistinct(): void
    {
        self::assertNotSame(FieldAuthorizationResult::Allowed, FieldAuthorizationResult::Redacted);
        self::assertNotSame(FieldAuthorizationResult::Redacted, FieldAuthorizationResult::Denied);
        self::assertNotSame(FieldAuthorizationResult::Allowed, FieldAuthorizationResult::Denied);
    }

    // ── ResponseContext ─────────────────────────────────────────────────

    #[Test]
    public function responseContextDefaults(): void
    {
        $ctx = new ResponseContext();

        self::assertSame('', $ctx->resourceType);
        self::assertNull($ctx->requestedFields);
        self::assertNull($ctx->paginationMeta);
        self::assertNull($ctx->paginationLinks);
        self::assertSame([], $ctx->meta);
    }

    #[Test]
    public function responseContextWithResourceType(): void
    {
        $ctx = new ResponseContext(resourceType: 'articles');

        self::assertSame('articles', $ctx->resourceType);
    }

    #[Test]
    public function responseContextWithRequestedFields(): void
    {
        $ctx = new ResponseContext(requestedFields: ['id', 'title', 'author']);

        self::assertSame(['id', 'title', 'author'], $ctx->requestedFields);
    }

    #[Test]
    public function responseContextWithPaginationMeta(): void
    {
        $meta = new PaginationMeta(perPage: 25, hasMore: true, total: 100);
        $ctx = new ResponseContext(paginationMeta: $meta);

        self::assertSame($meta, $ctx->paginationMeta);
        self::assertSame(25, $ctx->paginationMeta->perPage);
    }

    #[Test]
    public function responseContextWithPaginationLinks(): void
    {
        $links = new PaginationLinks(
            first: '/api/v1/articles?page=1',
            next: '/api/v1/articles?page=2',
        );
        $ctx = new ResponseContext(paginationLinks: $links);

        self::assertSame($links, $ctx->paginationLinks);
    }

    #[Test]
    public function responseContextWithMeta(): void
    {
        $ctx = new ResponseContext(meta: ['version' => '1.0', 'cache_hit' => true]);

        self::assertSame('1.0', $ctx->meta['version']);
        self::assertTrue($ctx->meta['cache_hit']);
    }

    #[Test]
    public function responseContextFullConstruction(): void
    {
        $meta = new PaginationMeta(perPage: 10, hasMore: false);
        $links = new PaginationLinks(first: '/first', last: '/last');

        $ctx = new ResponseContext(
            resourceType: 'users',
            requestedFields: ['id', 'email'],
            paginationMeta: $meta,
            paginationLinks: $links,
            meta: ['total_time_ms' => 42],
        );

        self::assertSame('users', $ctx->resourceType);
        self::assertSame(['id', 'email'], $ctx->requestedFields);
        self::assertSame($meta, $ctx->paginationMeta);
        self::assertSame($links, $ctx->paginationLinks);
        self::assertSame(42, $ctx->meta['total_time_ms']);
    }
}
