<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Client;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Client\InMemoryClientRepository;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;

final class InMemoryClientRepositoryTest extends TestCase
{
    #[Test]
    public function findByIdReturnsRegisteredClient(): void
    {
        $repo = new InMemoryClientRepository();
        $client = $this->buildClient('client-1');
        $repo->register($client);

        $found = $repo->findById('client-1');

        self::assertNotNull($found);
        self::assertSame('client-1', $found->id);
    }

    #[Test]
    public function findByIdReturnsNullForUnknownClient(): void
    {
        $repo = new InMemoryClientRepository();

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findByIdReturnsNullForInactiveClient(): void
    {
        $repo = new InMemoryClientRepository();
        $client = $this->buildClient('client-1', active: false);
        $repo->register($client);

        self::assertNull($repo->findById('client-1'));
    }

    #[Test]
    public function registerRejectsDuplicateId(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register($this->buildClient('dup'));

        $this->expectException(OAuth2Exception::class);
        $repo->register($this->buildClient('dup'));
    }

    #[Test]
    public function validateClientAcceptsPublicClientWithoutSecret(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register($this->buildClient('pub', confidential: false));

        self::assertTrue($repo->validateClient('pub', null, 'authorization_code'));
    }

    #[Test]
    public function validateClientRejectsPublicClientWithSecret(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register($this->buildClient('pub', confidential: false));

        self::assertFalse($repo->validateClient('pub', 'some-secret', 'authorization_code'));
    }

    #[Test]
    public function validateClientAcceptsConfidentialWithCorrectSecret(): void
    {
        $repo = new InMemoryClientRepository();
        $hash = password_hash('correct-secret', PASSWORD_BCRYPT);
        $repo->register($this->buildClient('conf', confidential: true, secretHash: $hash));

        self::assertTrue($repo->validateClient('conf', 'correct-secret', 'authorization_code'));
    }

    #[Test]
    public function validateClientRejectsConfidentialWithWrongSecret(): void
    {
        $repo = new InMemoryClientRepository();
        $hash = password_hash('real-secret', PASSWORD_BCRYPT);
        $repo->register($this->buildClient('conf', confidential: true, secretHash: $hash));

        self::assertFalse($repo->validateClient('conf', 'wrong-secret', 'authorization_code'));
    }

    #[Test]
    public function validateClientRejectsUnknownGrantType(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register($this->buildClient('c1', grantTypes: ['authorization_code']));

        self::assertFalse($repo->validateClient('c1', null, 'client_credentials'));
    }

    #[Test]
    public function validateClientRejectsInactiveClient(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register($this->buildClient('inactive', active: false));

        self::assertFalse($repo->validateClient('inactive', null, 'authorization_code'));
    }

    #[Test]
    public function revokeDeactivatesClient(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register($this->buildClient('revokable'));

        self::assertNotNull($repo->findById('revokable'));

        $repo->revoke('revokable');

        self::assertNull($repo->findById('revokable'));
    }

    #[Test]
    public function revokeNoOpsForNonExistentClient(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->revoke('nonexistent');

        $this->addToAssertionCount(1);
    }

    private function buildClient(
        string $id = 'client-1',
        bool $confidential = false,
        bool $active = true,
        ?string $secretHash = null,
        array $grantTypes = ['authorization_code', 'client_credentials'],
    ): OAuthClient {
        return new OAuthClient(
            id: $id,
            name: "Test Client $id",
            redirectUris: ['https://example.com/callback'],
            grantTypes: $grantTypes,
            scopes: ['openid', 'profile'],
            confidential: $confidential,
            active: $active,
            secretHash: $secretHash,
            createdAt: new DateTimeImmutable(),
        );
    }
}
