<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Oidc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\ScopeClaimsMapper;

final class ScopeClaimsMapperTest extends TestCase
{
    #[Test]
    public function claimsForOpenidScope(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['openid']);

        self::assertSame(['sub'], $claims);
    }

    #[Test]
    public function claimsForProfileScope(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['profile']);

        self::assertContains('name', $claims);
        self::assertContains('family_name', $claims);
        self::assertContains('given_name', $claims);
        self::assertContains('preferred_username', $claims);
        self::assertContains('picture', $claims);
        self::assertContains('gender', $claims);
        self::assertContains('birthdate', $claims);
        self::assertContains('locale', $claims);
        self::assertCount(14, $claims);
    }

    #[Test]
    public function claimsForEmailScope(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['email']);

        self::assertSame(['email', 'email_verified'], $claims);
    }

    #[Test]
    public function claimsForAddressScope(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['address']);

        self::assertSame(['address'], $claims);
    }

    #[Test]
    public function claimsForPhoneScope(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['phone']);

        self::assertSame(['phone_number', 'phone_number_verified'], $claims);
    }

    #[Test]
    public function claimsForMultipleScopesDeduplicates(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['openid', 'profile', 'email']);

        self::assertContains('sub', $claims);
        self::assertContains('name', $claims);
        self::assertContains('email', $claims);
        // No duplicates
        self::assertSame(array_unique($claims), $claims);
    }

    #[Test]
    public function claimsForEmptyScopes(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes([]);

        self::assertSame([], $claims);
    }

    #[Test]
    public function claimsForUnknownScopeReturnsEmpty(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['custom_scope', 'another']);

        self::assertSame([], $claims);
    }

    #[Test]
    public function filterClaimsReturnsOnlyAllowedClaims(): void
    {
        $allClaims = [
            'sub' => 'user-1',
            'name' => 'John Doe',
            'email' => 'john@test.com',
            'phone_number' => '+1234567890',
        ];

        $filtered = ScopeClaimsMapper::filterClaims($allClaims, ['openid', 'email']);

        self::assertSame([
            'sub' => 'user-1',
            'email' => 'john@test.com',
        ], $filtered);
    }

    #[Test]
    public function filterClaimsOmitsMissingClaims(): void
    {
        $allClaims = ['sub' => 'user-1'];

        $filtered = ScopeClaimsMapper::filterClaims($allClaims, ['openid', 'profile']);

        self::assertSame(['sub' => 'user-1'], $filtered);
    }

    #[Test]
    public function filterClaimsWithEmptyScopesReturnsEmpty(): void
    {
        $allClaims = ['sub' => 'user-1', 'email' => 'test@test.com'];

        $filtered = ScopeClaimsMapper::filterClaims($allClaims, []);

        self::assertSame([], $filtered);
    }

    #[Test]
    public function filterClaimsWithEmptyClaimsReturnsEmpty(): void
    {
        $filtered = ScopeClaimsMapper::filterClaims([], ['openid', 'profile', 'email']);

        self::assertSame([], $filtered);
    }

    #[Test]
    #[DataProvider('allStandardScopesProvider')]
    public function allStandardScopesReturnNonEmptyClaims(string $scope): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes([$scope]);

        self::assertNotEmpty($claims, "Scope '$scope' should return at least one claim");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allStandardScopesProvider(): iterable
    {
        yield 'openid' => ['openid'];
        yield 'profile' => ['profile'];
        yield 'email' => ['email'];
        yield 'address' => ['address'];
        yield 'phone' => ['phone'];
    }
}
