<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Consent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Consent\InMemoryConsentRepository;

final class InMemoryConsentRepositoryTest extends TestCase
{
    private InMemoryConsentRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryConsentRepository();
    }

    #[Test]
    public function has_consent_returns_false_when_none_granted(): void
    {
        self::assertFalse($this->repo->hasConsent('user-1', 'client-1', ['read']));
    }

    #[Test]
    public function grant_and_check_consent(): void
    {
        $this->repo->grantConsent('user-1', 'client-1', ['read', 'write']);

        self::assertTrue($this->repo->hasConsent('user-1', 'client-1', ['read']));
        self::assertTrue($this->repo->hasConsent('user-1', 'client-1', ['read', 'write']));
        self::assertFalse($this->repo->hasConsent('user-1', 'client-1', ['admin']));
    }

    #[Test]
    public function grant_consent_merges_scopes(): void
    {
        $this->repo->grantConsent('user-1', 'client-1', ['read']);
        $this->repo->grantConsent('user-1', 'client-1', ['write']);

        self::assertTrue($this->repo->hasConsent('user-1', 'client-1', ['read', 'write']));
    }

    #[Test]
    public function revoke_consent_removes_record(): void
    {
        $this->repo->grantConsent('user-1', 'client-1', ['read']);
        $this->repo->revokeConsent('user-1', 'client-1');

        self::assertFalse($this->repo->hasConsent('user-1', 'client-1', ['read']));
    }

    #[Test]
    public function list_consents_returns_all_for_subject(): void
    {
        $this->repo->grantConsent('user-1', 'client-1', ['read']);
        $this->repo->grantConsent('user-1', 'client-2', ['write']);
        $this->repo->grantConsent('user-2', 'client-1', ['admin']);

        $consents = $this->repo->listConsents('user-1');

        self::assertCount(2, $consents);
    }

    #[Test]
    public function grant_consent_returns_record_with_correct_fields(): void
    {
        $record = $this->repo->grantConsent('user-1', 'client-1', ['openid', 'profile']);

        self::assertSame('user-1', $record->subjectId);
        self::assertSame('client-1', $record->clientId);
        self::assertSame(['openid', 'profile'], $record->scopes);
        self::assertNotEmpty($record->id);
    }
}
