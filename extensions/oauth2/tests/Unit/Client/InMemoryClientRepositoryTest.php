<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Client\InMemoryClientRepository;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;

use function password_hash;

use const PASSWORD_BCRYPT;

final class InMemoryClientRepositoryTest extends TestCase
{
    private InMemoryClientRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryClientRepository();
    }

    #[Test]
    public function findByIdReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->repo->findById('unknown'));
    }

    #[Test]
    public function findByIdReturnsRegisteredClient(): void
    {
        $client = new OAuthClient(
            id: 'my-client',
            name: 'My App',
            redirectUris: ['https://app.test/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
            confidential: true,
        );
        $this->repo->register($client);

        $found = $this->repo->findById('my-client');

        self::assertNotNull($found);
        self::assertSame('my-client', $found->id);
        self::assertSame('My App', $found->name);
    }

    #[Test]
    public function findByIdReturnsNullForInactiveClient(): void
    {
        $client = new OAuthClient(
            id: 'inactive',
            name: 'Inactive',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: false,
            active: false,
        );
        $this->repo->register($client);

        self::assertNull($this->repo->findById('inactive'));
    }

    #[Test]
    public function registerThrowsForDuplicateClient(): void
    {
        $client = new OAuthClient(
            id: 'dup',
            name: 'First',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: false,
        );
        $this->repo->register($client);

        $this->expectException(OAuth2Exception::class);
        $this->expectExceptionMessage("Client 'dup' already exists");

        $this->repo->register($client);
    }

    #[Test]
    public function validateClientReturnsFalseForUnknownClient(): void
    {
        self::assertFalse($this->repo->validateClient('unknown', null, 'authorization_code'));
    }

    #[Test]
    public function validateClientReturnsFalseForInactiveClient(): void
    {
        $client = new OAuthClient(
            id: 'inactive',
            name: 'Inactive',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: false,
            active: false,
        );
        $this->repo->register($client);

        self::assertFalse($this->repo->validateClient('inactive', null, 'authorization_code'));
    }

    #[Test]
    public function validateClientReturnsFalseForDisallowedGrantType(): void
    {
        $client = new OAuthClient(
            id: 'limited',
            name: 'Limited',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: false,
        );
        $this->repo->register($client);

        self::assertFalse($this->repo->validateClient('limited', null, 'client_credentials'));
    }

    #[Test]
    public function validateClientConfidentialWithCorrectSecret(): void
    {
        $hash = password_hash('my-secret', PASSWORD_BCRYPT);
        $client = new OAuthClient(
            id: 'conf',
            name: 'Confidential',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
            secretHash: $hash,
        );
        $this->repo->register($client);

        self::assertTrue($this->repo->validateClient('conf', 'my-secret', 'client_credentials'));
    }

    #[Test]
    public function validateClientConfidentialWithWrongSecret(): void
    {
        $hash = password_hash('correct-secret', PASSWORD_BCRYPT);
        $client = new OAuthClient(
            id: 'conf',
            name: 'Confidential',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
            secretHash: $hash,
        );
        $this->repo->register($client);

        self::assertFalse($this->repo->validateClient('conf', 'wrong-secret', 'client_credentials'));
    }

    #[Test]
    public function validateClientConfidentialWithNullSecret(): void
    {
        $client = new OAuthClient(
            id: 'conf-no-secret',
            name: 'Confidential',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
            secretHash: password_hash('secret', PASSWORD_BCRYPT),
        );
        $this->repo->register($client);

        self::assertFalse($this->repo->validateClient('conf-no-secret', null, 'client_credentials'));
    }

    #[Test]
    public function validateClientConfidentialWithNullSecretHash(): void
    {
        $client = new OAuthClient(
            id: 'no-hash',
            name: 'No Hash',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: true,
            secretHash: null,
        );
        $this->repo->register($client);

        self::assertFalse($this->repo->validateClient('no-hash', 'any-secret', 'client_credentials'));
    }

    #[Test]
    public function validateClientPublicWithoutSecret(): void
    {
        $client = new OAuthClient(
            id: 'pub',
            name: 'Public',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: false,
        );
        $this->repo->register($client);

        self::assertTrue($this->repo->validateClient('pub', null, 'authorization_code'));
    }

    #[Test]
    public function validateClientPublicRejectsSecret(): void
    {
        $client = new OAuthClient(
            id: 'pub',
            name: 'Public',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: false,
        );
        $this->repo->register($client);

        self::assertFalse($this->repo->validateClient('pub', 'some-secret', 'authorization_code'));
    }

    #[Test]
    public function revokeDeactivatesClient(): void
    {
        $client = new OAuthClient(
            id: 'to-revoke',
            name: 'Revocable',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: false,
        );
        $this->repo->register($client);

        self::assertNotNull($this->repo->findById('to-revoke'));

        $this->repo->revoke('to-revoke');

        self::assertNull($this->repo->findById('to-revoke'));
    }

    #[Test]
    public function revokeNonExistentClientIsNoOp(): void
    {
        $this->repo->revoke('does-not-exist');

        self::assertNull($this->repo->findById('does-not-exist'));
    }

    #[Test]
    public function revokedClientFailsValidation(): void
    {
        $client = new OAuthClient(
            id: 'rev-val',
            name: 'Test',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: false,
        );
        $this->repo->register($client);
        $this->repo->revoke('rev-val');

        self::assertFalse($this->repo->validateClient('rev-val', null, 'authorization_code'));
    }
}
