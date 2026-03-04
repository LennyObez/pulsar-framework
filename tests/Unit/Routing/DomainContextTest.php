<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\DomainContext;

#[CoversClass(DomainContext::class)]
final class DomainContextTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $ctx = new DomainContext(
            domain: 'forum.example.com',
            subdomain: 'forum',
            extensionScopes: ['forum'],
            isDefault: false,
        );

        self::assertSame('forum.example.com', $ctx->domain);
        self::assertSame('forum', $ctx->subdomain);
        self::assertSame(['forum'], $ctx->extensionScopes);
        self::assertFalse($ctx->isDefault);
    }

    #[Test]
    public function defaultContextAllowsAllScopes(): void
    {
        $ctx = new DomainContext(
            domain: 'example.com',
            subdomain: '',
            extensionScopes: [],
            isDefault: true,
        );

        self::assertTrue($ctx->hasScope('anything'));
        self::assertTrue($ctx->hasScope('forum'));
        self::assertTrue($ctx->hasScope('cms-admin'));
    }

    #[Test]
    public function nonDefaultContextFiltersScopes(): void
    {
        $ctx = new DomainContext(
            domain: 'forum.example.com',
            subdomain: 'forum',
            extensionScopes: ['forum', 'forum-api'],
            isDefault: false,
        );

        self::assertTrue($ctx->hasScope('forum'));
        self::assertTrue($ctx->hasScope('forum-api'));
        self::assertFalse($ctx->hasScope('cms-admin'));
        self::assertFalse($ctx->hasScope('api'));
    }

    #[Test]
    public function nonDefaultContextWithEmptyScopesRejectsAll(): void
    {
        $ctx = new DomainContext(
            domain: 'unknown.example.com',
            subdomain: 'unknown',
            extensionScopes: [],
            isDefault: false,
        );

        self::assertFalse($ctx->hasScope('forum'));
    }

    /**
     * @return iterable<string, array{DomainContext, string, bool}>
     */
    public static function scopeCheckProvider(): iterable
    {
        $default = new DomainContext('example.com', '', [], true);
        $forum = new DomainContext('forum.example.com', 'forum', ['forum'], false);
        $multi = new DomainContext('api.example.com', 'api', ['api', 'graphql'], false);

        yield 'default allows any scope' => [$default, 'whatever', true];
        yield 'forum allows forum' => [$forum, 'forum', true];
        yield 'forum rejects admin' => [$forum, 'admin', false];
        yield 'multi allows api' => [$multi, 'api', true];
        yield 'multi allows graphql' => [$multi, 'graphql', true];
        yield 'multi rejects forum' => [$multi, 'forum', false];
    }

    #[Test]
    #[DataProvider('scopeCheckProvider')]
    public function hasScopeWithDataProvider(DomainContext $ctx, string $scope, bool $expected): void
    {
        self::assertSame($expected, $ctx->hasScope($scope));
    }
}
