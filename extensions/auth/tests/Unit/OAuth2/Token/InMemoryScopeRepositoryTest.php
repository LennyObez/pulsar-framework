<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryScopeRepository;
use Pulsar\Extension\Auth\OAuth2\Token\Scope;

final class InMemoryScopeRepositoryTest extends TestCase
{
    private InMemoryScopeRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryScopeRepository();
    }

    #[Test]
    public function findByIdReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->repo->findById('openid'));
    }

    #[Test]
    public function findByIdReturnsAddedScope(): void
    {
        $scope = new Scope('openid', 'OpenID scope');
        $this->repo->add($scope);

        $found = $this->repo->findById('openid');

        self::assertNotNull($found);
        self::assertSame('openid', $found->id);
        self::assertSame('OpenID scope', $found->description);
    }

    #[Test]
    public function findByIdReturnsNullForUnknownScope(): void
    {
        $this->repo->add(new Scope('openid'));

        self::assertNull($this->repo->findById('profile'));
    }

    #[Test]
    public function resolveScopesReturnsOnlyKnownScopes(): void
    {
        $this->repo->add(new Scope('openid'));
        $this->repo->add(new Scope('profile'));

        $resolved = $this->repo->resolveScopes(['openid', 'unknown', 'profile'], 'authorization_code', 'client-1');

        self::assertCount(2, $resolved);
        self::assertSame('openid', $resolved[0]->id);
        self::assertSame('profile', $resolved[1]->id);
    }

    #[Test]
    public function resolveScopesReturnsEmptyForAllUnknown(): void
    {
        $resolved = $this->repo->resolveScopes(['foo', 'bar'], 'client_credentials', 'client-1');

        self::assertSame([], $resolved);
    }

    #[Test]
    public function resolveScopesReturnsEmptyForEmptyInput(): void
    {
        $this->repo->add(new Scope('openid'));

        $resolved = $this->repo->resolveScopes([], 'authorization_code', 'client-1');

        self::assertSame([], $resolved);
    }

    #[Test]
    public function addOverwritesExistingScope(): void
    {
        $this->repo->add(new Scope('openid', 'Original'));
        $this->repo->add(new Scope('openid', 'Updated'));

        $found = $this->repo->findById('openid');

        self::assertNotNull($found);
        self::assertSame('Updated', $found->description);
    }

    #[Test]
    public function resolveScopesPreservesOrder(): void
    {
        $this->repo->add(new Scope('email'));
        $this->repo->add(new Scope('profile'));
        $this->repo->add(new Scope('openid'));

        $resolved = $this->repo->resolveScopes(['profile', 'openid', 'email'], 'authorization_code', 'c1');

        self::assertSame('profile', $resolved[0]->id);
        self::assertSame('openid', $resolved[1]->id);
        self::assertSame('email', $resolved[2]->id);
    }
}
