<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionManager;
use ReflectionClass;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(SessionManager::class)]
final class SessionManagerEncryptionTest extends TestCase
{
    #[Test]
    public function encryptedSessionPersistsAndRestoresData(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'SECURE_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: true,
        );

        $manager = new SessionManager($handler, $config, encryption: $encryption);
        $manager->start();

        $manager->set('secret_data', 'classified-value');
        $manager->set('user_id', 'admin-42');
        $manager->save();

        $sessionId = $manager->id();

        // Raw stored data should be encrypted (not plaintext)
        $raw = $handler->read($sessionId);
        self::assertNotEmpty($raw);
        self::assertStringNotContainsString('classified-value', $raw);

        // New manager reading the same session should decrypt and restore data
        $manager2 = new SessionManager($handler, $config, encryption: $encryption);
        $reflection = new ReflectionClass($manager2);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($manager2, $sessionId);

        $manager2->start();

        self::assertSame('classified-value', $manager2->get('secret_data'));
        self::assertSame('admin-42', $manager2->get('user_id'));
    }

    #[Test]
    public function encryptedSessionWithRequestStartsCorrectly(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'ENC_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: true,
        );

        // First request: create session
        $manager = new SessionManager($handler, $config, encryption: $encryption);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            headers: ['User-Agent' => 'Chrome/130'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
        );

        $manager->startWithRequest($request);
        $manager->set('role', 'admin');
        $manager->save();

        $sessionId = $manager->id();

        // Second request: resume encrypted session
        $manager2 = new SessionManager($handler, $config, encryption: $encryption);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/settings',
            headers: ['User-Agent' => 'Chrome/130'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
            cookieParams: ['ENC_SESSION' => $sessionId],
        );

        $manager2->startWithRequest($request2);

        self::assertSame('admin', $manager2->get('role'));
        self::assertNotNull($manager2->metadata);
    }

    #[Test]
    public function regenerateWithEncryptionReEncryptsUnderNewId(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'REGEN_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: true,
        );

        $manager = new SessionManager($handler, $config, encryption: $encryption);
        $manager->start();
        $manager->set('key', 'value');
        $oldId = $manager->id();

        $manager->regenerate();
        $newId = $manager->id();

        self::assertNotSame($oldId, $newId);
        self::assertSame('value', $manager->get('key'));

        // Old session should be destroyed
        self::assertSame('', $handler->read($oldId));

        // New session should contain encrypted data
        $raw = $handler->read($newId);
        self::assertNotEmpty($raw);
        self::assertStringNotContainsString('value', $raw);
    }

    #[Test]
    public function closeWithEncryptionPersistsEncryptedData(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'CLOSE_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: true,
        );

        $manager = new SessionManager($handler, $config, encryption: $encryption);
        $manager->start();
        $manager->set('balance', '50000');
        $manager->close();

        $raw = $handler->read($manager->id());
        self::assertNotEmpty($raw);
        self::assertStringNotContainsString('50000', $raw);
    }

    #[Test]
    public function startWithRequestResumesExistingMetadata(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'META_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        // First request: create session with metadata
        $manager = new SessionManager($handler, $config);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Firefox/125'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
        );

        $manager->startWithRequest($request);
        $manager->setUserId('user-77');
        $manager->save();

        $sessionId = $manager->id();
        $createdAt = $manager->metadata?->createdAt;

        // Second request: resume session, metadata should update lastActivity but preserve createdAt
        $manager2 = new SessionManager($handler, $config);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/next',
            headers: ['User-Agent' => 'Firefox/125'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.1'],
            cookieParams: ['META_SESSION' => $sessionId],
        );

        $manager2->startWithRequest($request2);

        self::assertNotNull($manager2->metadata);
        self::assertSame($createdAt, $manager2->metadata->createdAt);
        self::assertSame('user-77', $manager2->metadata->userId);
        // IP and UA are preserved from original session
        self::assertSame('192.168.1.1', $manager2->metadata->ipAddress);
        self::assertSame('Firefox/125', $manager2->metadata->userAgent);
    }

    #[Test]
    public function startWithCorruptedSerializedDataCreatesNewSession(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'CORRUPT_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        // Write corrupted data directly into the handler
        $sessionId = str_repeat('a', 64);
        self::assertTrue($handler->open('', ''));
        $handler->write($sessionId, 'this-is-not-valid-serialized-data{{{');

        $manager = new SessionManager($handler, $config);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome/130'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
            cookieParams: ['CORRUPT_SESSION' => $sessionId],
        );

        $manager->startWithRequest($request);

        // Should start with empty data and fresh metadata
        self::assertTrue($manager->isStarted());
        self::assertSame([], $manager->all());
        self::assertNotNull($manager->metadata);
    }
}
