<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Oidc\ScopeClaimsMapper;

#[CoversClass(ScopeClaimsMapper::class)]
final class ScopeClaimsMapperTest extends TestCase
{
    #[Test]
    public function openidScopeReturnsSub(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['openid']);

        self::assertContains('sub', $claims);
    }

    #[Test]
    public function profileScopeReturnsProfileClaims(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['profile']);

        self::assertContains('name', $claims);
        self::assertContains('family_name', $claims);
        self::assertContains('given_name', $claims);
        self::assertContains('middle_name', $claims);
        self::assertContains('nickname', $claims);
        self::assertContains('preferred_username', $claims);
        self::assertContains('profile', $claims);
        self::assertContains('picture', $claims);
        self::assertContains('website', $claims);
        self::assertContains('gender', $claims);
        self::assertContains('birthdate', $claims);
        self::assertContains('zoneinfo', $claims);
        self::assertContains('locale', $claims);
        self::assertContains('updated_at', $claims);
    }

    #[Test]
    public function emailScopeReturnsEmailClaims(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['email']);

        self::assertContains('email', $claims);
        self::assertContains('email_verified', $claims);
        self::assertCount(2, $claims);
    }

    #[Test]
    public function addressScopeReturnsAddressClaim(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['address']);

        self::assertContains('address', $claims);
        self::assertCount(1, $claims);
    }

    #[Test]
    public function phoneScopeReturnsPhoneClaims(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['phone']);

        self::assertContains('phone_number', $claims);
        self::assertContains('phone_number_verified', $claims);
        self::assertCount(2, $claims);
    }

    #[Test]
    public function multipleScopesMergeClaims(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['openid', 'email', 'phone']);

        self::assertContains('sub', $claims);
        self::assertContains('email', $claims);
        self::assertContains('email_verified', $claims);
        self::assertContains('phone_number', $claims);
        self::assertContains('phone_number_verified', $claims);
    }

    #[Test]
    public function duplicateClaimsAreDeduped(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['openid', 'openid']);

        $subCount = 0;
        foreach ($claims as $claim) {
            if ($claim === 'sub') {
                $subCount++;
            }
        }
        self::assertSame(1, $subCount);
    }

    #[Test]
    public function unknownScopeIgnored(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['unknown_scope']);

        self::assertSame([], $claims);
    }

    #[Test]
    public function emptyScopes(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes([]);

        self::assertSame([], $claims);
    }

    #[Test]
    public function filterClaimsReturnsOnlyAuthorizedClaims(): void
    {
        $allClaims = [
            'sub' => 'user-42',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'email_verified' => true,
            'phone_number' => '+1234567890',
        ];

        $filtered = ScopeClaimsMapper::filterClaims($allClaims, ['openid', 'email']);

        self::assertSame('user-42', $filtered['sub']);
        self::assertSame('john@example.com', $filtered['email']);
        self::assertTrue($filtered['email_verified']);
        self::assertArrayNotHasKey('name', $filtered);
        self::assertArrayNotHasKey('phone_number', $filtered);
    }

    #[Test]
    public function filterClaimsHandlesMissingClaimsGracefully(): void
    {
        $allClaims = [
            'sub' => 'user-42',
            // email and email_verified are missing
        ];

        $filtered = ScopeClaimsMapper::filterClaims($allClaims, ['openid', 'email']);

        self::assertSame('user-42', $filtered['sub']);
        self::assertArrayNotHasKey('email', $filtered);
        self::assertArrayNotHasKey('email_verified', $filtered);
    }

    #[Test]
    public function allOidcScopesCombined(): void
    {
        $claims = ScopeClaimsMapper::claimsForScopes(['openid', 'profile', 'email', 'address', 'phone']);

        // Should contain claims from all standard scopes
        self::assertContains('sub', $claims);
        self::assertContains('name', $claims);
        self::assertContains('email', $claims);
        self::assertContains('address', $claims);
        self::assertContains('phone_number', $claims);
    }
}
