<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\Scope;

#[CoversClass(Scope::class)]
final class ScopeTest extends TestCase
{
    #[Test]
    public function constructionPreservesFields(): void
    {
        $scope = new Scope(id: 'openid', description: 'OpenID Connect scope');

        self::assertSame('openid', $scope->id);
        self::assertSame('OpenID Connect scope', $scope->description);
    }

    #[Test]
    public function descriptionDefaultsToEmptyString(): void
    {
        $scope = new Scope(id: 'profile');

        self::assertSame('', $scope->description);
    }

    #[Test]
    public function toStringReturnsId(): void
    {
        $scope = new Scope(id: 'email', description: 'Email scope');

        self::assertSame('email', (string) $scope);
    }

    #[Test]
    public function canBeUsedInStringContext(): void
    {
        $scope = new Scope(id: 'openid');

        $result = "scope:{$scope}";

        self::assertSame('scope:openid', $result);
    }
}
