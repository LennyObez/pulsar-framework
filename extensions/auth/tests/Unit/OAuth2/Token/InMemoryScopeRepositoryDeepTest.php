<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryScopeRepository;
use Pulsar\Extension\Auth\OAuth2\Token\Scope;

#[CoversClass(InMemoryScopeRepository::class)]
final class InMemoryScopeRepositoryDeepTest extends TestCase
{
    #[Test]
    public function addAndFindById(): void
    {
        $repo = new InMemoryScopeRepository();
        $scope = new Scope(id: 'openid', description: 'OpenID');
        $repo->add($scope);

        self::assertSame($scope, $repo->findById('openid'));
    }

    #[Test]
    public function findByIdReturnsNullForMissing(): void
    {
        $repo = new InMemoryScopeRepository();

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function resolveScopesReturnsMathingScopes(): void
    {
        $repo = new InMemoryScopeRepository();
        $repo->add(new Scope(id: 'openid', description: 'OpenID'));
        $repo->add(new Scope(id: 'email', description: 'Email'));
        $repo->add(new Scope(id: 'profile', description: 'Profile'));

        $resolved = $repo->resolveScopes(['openid', 'email', 'phone'], 'authorization_code', 'client-1');

        self::assertCount(2, $resolved);
        self::assertSame('openid', $resolved[0]->id);
        self::assertSame('email', $resolved[1]->id);
    }

    #[Test]
    public function resolveScopesReturnsEmptyForNoMatches(): void
    {
        $repo = new InMemoryScopeRepository();

        $resolved = $repo->resolveScopes(['unknown'], 'client_credentials', 'client-1');

        self::assertSame([], $resolved);
    }
}
