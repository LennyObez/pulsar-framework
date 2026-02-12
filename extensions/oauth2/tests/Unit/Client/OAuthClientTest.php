<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Client\InMemoryClientRepository;
use Pulsar\Extension\OAuth2\Client\OAuthClient;
use Pulsar\Extension\OAuth2\Exception\OAuth2Exception;

final class OAuthClientTest extends TestCase
{
    #[Test]
    public function has_redirect_uri_exact_match(): void
    {
        $client = new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: ['https://example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['read'],
            confidential: true,
        );

        self::assertTrue($client->hasRedirectUri('https://example.com/callback'));
        self::assertFalse($client->hasRedirectUri('https://example.com/other'));
        self::assertFalse($client->hasRedirectUri('https://example.com/callback/'));
    }

    #[Test]
    public function has_grant_type(): void
    {
        $client = new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: [],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: [],
            confidential: false,
        );

        self::assertTrue($client->hasGrantType('authorization_code'));
        self::assertTrue($client->hasGrantType('refresh_token'));
        self::assertFalse($client->hasGrantType('client_credentials'));
    }

    #[Test]
    public function debug_info_redacts_secret(): void
    {
        $client = new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: true,
            secretHash: '$2y$10$somehash',
        );

        $debug = $client->__debugInfo();

        self::assertSame('[REDACTED]', $debug['secretHash']);
    }

    #[Test]
    public function debug_info_shows_null_when_no_secret(): void
    {
        $client = new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: false,
        );

        $debug = $client->__debugInfo();

        self::assertNull($debug['secretHash']);
    }

    #[Test]
    public function in_memory_repo_register_and_find(): void
    {
        $repo = new InMemoryClientRepository();
        $client = new OAuthClient(
            id: 'c1',
            name: 'Test App',
            redirectUris: ['https://example.com/cb'],
            grantTypes: ['authorization_code'],
            scopes: ['read'],
            confidential: false,
        );

        $repo->register($client);
        $found = $repo->findById('c1');

        self::assertNotNull($found);
        self::assertSame('Test App', $found->name);
    }

    #[Test]
    public function in_memory_repo_find_returns_null_for_inactive(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register(new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: false,
            active: false,
        ));

        self::assertNull($repo->findById('c1'));
    }

    #[Test]
    public function in_memory_repo_duplicate_registration_throws(): void
    {
        $repo = new InMemoryClientRepository();
        $client = new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: [],
            grantTypes: [],
            scopes: [],
            confidential: false,
        );

        $repo->register($client);

        $this->expectException(OAuth2Exception::class);
        $repo->register($client);
    }

    #[Test]
    public function in_memory_repo_revoke_deactivates_client(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register(new OAuthClient(
            id: 'c1',
            name: 'Test',
            redirectUris: [],
            grantTypes: ['client_credentials'],
            scopes: [],
            confidential: false,
        ));

        $repo->revoke('c1');

        self::assertNull($repo->findById('c1'));
    }

    #[Test]
    public function in_memory_repo_validate_public_client_rejects_secret(): void
    {
        $repo = new InMemoryClientRepository();
        $repo->register(new OAuthClient(
            id: 'pub',
            name: 'Public',
            redirectUris: [],
            grantTypes: ['authorization_code'],
            scopes: [],
            confidential: false,
        ));

        self::assertTrue($repo->validateClient('pub', null, 'authorization_code'));
        self::assertFalse($repo->validateClient('pub', 'any-secret', 'authorization_code'));
    }
}
