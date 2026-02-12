<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Token;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Token\InMemoryScopeRepository;
use Pulsar\Extension\OAuth2\Token\Scope;

final class ScopeTest extends TestCase
{
    #[Test]
    public function scope_construction(): void
    {
        $scope = new Scope('read', 'Read access');

        self::assertSame('read', $scope->id);
        self::assertSame('Read access', $scope->description);
    }

    #[Test]
    public function scope_to_string_returns_id(): void
    {
        $scope = new Scope('openid');

        self::assertSame('openid', (string) $scope);
    }

    #[Test]
    public function scope_default_description_is_empty(): void
    {
        $scope = new Scope('profile');

        self::assertSame('', $scope->description);
    }

    #[Test]
    public function in_memory_repo_find_by_id(): void
    {
        $repo = new InMemoryScopeRepository();
        $repo->add(new Scope('read', 'Read'));
        $repo->add(new Scope('write', 'Write'));

        $found = $repo->findById('read');
        self::assertNotNull($found);
        self::assertSame('read', $found->id);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function in_memory_repo_resolve_scopes_filters_to_registered(): void
    {
        $repo = new InMemoryScopeRepository();
        $repo->add(new Scope('read'));
        $repo->add(new Scope('write'));

        $resolved = $repo->resolveScopes(['read', 'admin'], 'authorization_code', 'client-1');

        self::assertCount(1, $resolved);
        self::assertSame('read', $resolved[0]->id);
    }
}
