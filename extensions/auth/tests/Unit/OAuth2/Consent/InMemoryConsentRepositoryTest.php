<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Consent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Consent\InMemoryConsentRepository;

final class InMemoryConsentRepositoryTest extends TestCase
{
    #[Test]
    public function hasConsentReturnsFalseWhenNoConsentExists(): void
    {
        $repo = new InMemoryConsentRepository();

        self::assertFalse($repo->hasConsent('user-1', 'client-1', ['openid']));
    }

    #[Test]
    public function grantConsentCreatesRecordAndHasConsentReturnsTrue(): void
    {
        $repo = new InMemoryConsentRepository();
        $record = $repo->grantConsent('user-1', 'client-1', ['openid', 'profile']);

        self::assertSame('user-1', $record->subjectId);
        self::assertSame('client-1', $record->clientId);
        self::assertContains('openid', $record->scopes);
        self::assertContains('profile', $record->scopes);

        self::assertTrue($repo->hasConsent('user-1', 'client-1', ['openid']));
        self::assertTrue($repo->hasConsent('user-1', 'client-1', ['openid', 'profile']));
    }

    #[Test]
    public function hasConsentReturnsFalseForUncoveredScopes(): void
    {
        $repo = new InMemoryConsentRepository();
        $repo->grantConsent('user-1', 'client-1', ['openid']);

        self::assertFalse($repo->hasConsent('user-1', 'client-1', ['openid', 'email']));
    }

    #[Test]
    public function grantConsentMergesWithExistingScopes(): void
    {
        $repo = new InMemoryConsentRepository();
        $repo->grantConsent('user-1', 'client-1', ['openid']);
        $repo->grantConsent('user-1', 'client-1', ['email']);

        self::assertTrue($repo->hasConsent('user-1', 'client-1', ['openid', 'email']));
    }

    #[Test]
    public function revokeConsentRemovesRecord(): void
    {
        $repo = new InMemoryConsentRepository();
        $repo->grantConsent('user-1', 'client-1', ['openid']);

        $repo->revokeConsent('user-1', 'client-1');

        self::assertFalse($repo->hasConsent('user-1', 'client-1', ['openid']));
    }

    #[Test]
    public function listConsentsReturnsAllForSubject(): void
    {
        $repo = new InMemoryConsentRepository();
        $repo->grantConsent('user-1', 'client-a', ['openid']);
        $repo->grantConsent('user-1', 'client-b', ['profile']);
        $repo->grantConsent('user-2', 'client-a', ['email']);

        $consents = $repo->listConsents('user-1');

        self::assertCount(2, $consents);
    }

    #[Test]
    public function listConsentsReturnsEmptyForUnknownSubject(): void
    {
        $repo = new InMemoryConsentRepository();

        self::assertSame([], $repo->listConsents('nobody'));
    }

    #[Test]
    public function consentsAreIsolatedBySubjectAndClient(): void
    {
        $repo = new InMemoryConsentRepository();
        $repo->grantConsent('user-1', 'client-1', ['admin']);

        self::assertFalse($repo->hasConsent('user-2', 'client-1', ['admin']));
        self::assertFalse($repo->hasConsent('user-1', 'client-2', ['admin']));
    }
}
