<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\Handler\CookieHandler;
use Pulsar\Security\Session\Handler\DatabaseHandler;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\Validator\UserAgentValidator;
use ReflectionClass;

use function random_bytes;
use function sodium_bin2hex;
use function str_repeat;

/**
 * Adversarial tests targeting security boundaries of the session system.
 */
#[CoversClass(SessionManager::class)]
final class SessionAdversarialTest extends TestCase
{
    #[Test]
    public function sessionFixationOldIdDestroyedAfterRegenerate(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        $manager = new SessionManager($handler, $config);
        $manager->start();

        $manager->set('role', 'guest');
        $manager->save();
        $oldId = $manager->id();

        // Simulate login — regenerate session ID
        $manager->regenerate(deleteOldSession: true);
        $newId = $manager->id();

        // Old session data should no longer exist in handler
        self::assertSame('', $handler->read($oldId));

        // New session should have the data
        self::assertSame('guest', $manager->get('role'));
        self::assertNotSame($oldId, $newId);
    }

    #[Test]
    public function fixationAttackerCannotUseOldSessionId(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        // Victim starts session
        $victim = new SessionManager($handler, $config);
        $victim->start();
        $victim->set('secret', 'bank_account_data');
        $victim->save();
        $victimOldId = $victim->id();

        // Victim logs in — regenerate ID
        $victim->regenerate(deleteOldSession: true);
        $victim->save();

        // Attacker tries to use the old session ID
        $attacker = new SessionManager($handler, $config);
        $reflection = new ReflectionClass($attacker);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($attacker, $victimOldId);
        $attacker->start();

        // Attacker should NOT have access to victim's data
        self::assertFalse($attacker->has('secret'));
    }

    #[Test]
    public function cookieHandlerRejectsOversizedPayload(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'cookie',
            encryption: true,
            cookieMaxPayloadSize: 512,
        );

        $handler = new CookieHandler($encryption, $config);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $handler->write('session-1', str_repeat('x', 513));
    }

    #[Test]
    public function tamperedSessionDataRejected(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        // Write valid session
        $manager = new SessionManager($handler, $config);
        $manager->start();
        $manager->set('role', 'admin');
        $manager->save();
        $sessionId = $manager->id();

        // Tamper with the raw handler data
        $handler->write($sessionId, 'CORRUPTED_NOT_SERIALIZABLE_DATA');

        // New manager reads the tampered data
        $manager2 = new SessionManager($handler, $config);
        $reflection = new ReflectionClass($manager2);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($manager2, $sessionId);
        $manager2->start();

        // Tampered data should result in empty session (unserialize fails)
        self::assertFalse($manager2->has('role'));
    }

    #[Test]
    public function validatorBypassDifferentIpRejected(): void
    {
        $validator = new \Pulsar\Security\Session\Validator\RemoteAddressValidator(mode: 'strict');
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        // Legitimate user starts session
        $manager = new SessionManager($handler, $config, [$validator]);
        $request1 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.100'],
        );
        $manager->startWithRequest($request1);
        $manager->set('secret', 'sensitive');
        $manager->save();
        $sessionId = $manager->id();

        // Attacker tries from different IP
        $manager2 = new SessionManager($handler, $config, [$validator]);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Session validation failed: remote_address');

        $manager2->startWithRequest($request2);
    }

    #[Test]
    public function validatorBypassDifferentUaRejected(): void
    {
        $validator = new UserAgentValidator('strict');
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        $manager = new SessionManager($handler, $config, [$validator]);
        $request1 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Chrome/120'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $manager->startWithRequest($request1);
        $manager->save();
        $sessionId = $manager->id();

        // Attacker with different user agent
        $manager2 = new SessionManager($handler, $config, [$validator]);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'curl/7.0'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Session validation failed: user_agent');

        $manager2->startWithRequest($request2);
    }

    #[Test]
    public function concurrentSessionLimitRaceSimulation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE sessions (
                id VARCHAR(128) PRIMARY KEY,
                user_id VARCHAR(255) NULL,
                data TEXT NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                last_activity INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )',
        );

        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'database',
            encryption: false,
            maxConcurrentSessions: 2,
        );

        $dbHandler = new DatabaseHandler($pdo, 'sessions', 3600);

        // Fill up to the limit
        $dbHandler->setSessionContext('s1', 'user-1', '10.0.0.1', 'Agent');
        $dbHandler->write('s1', 'data1');
        $dbHandler->setSessionContext('s2', 'user-1', '10.0.0.2', 'Agent');
        $dbHandler->write('s2', 'data2');

        $manager = new SessionManager($dbHandler, $config);

        // Third session should be rejected
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Concurrent session limit exceeded');

        $manager->enforceConcurrencyLimit('user-1');
    }

    #[Test]
    public function encryptedSessionTamperedCiphertextFails(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);

        $plaintext = 'secret session data';
        $encrypted = $encryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        // Tamper with the base64-encoded ciphertext
        $tampered = $encrypted . 'X';

        $this->expectException(SecurityException::class);

        $encryption->decrypt($tampered, 'session-1', 'file', 'example.com');
    }

    #[Test]
    public function destroyPreventsSubsequentOperations(): void
    {
        $handler = new ArrayHandler();
        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: false,
        );

        $manager = new SessionManager($handler, $config);
        $manager->start();
        $manager->set('key', 'value');
        $manager->destroy();

        // After destroy, operations should throw
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not been started');

        $_ = $manager->get('key');
    }
}
