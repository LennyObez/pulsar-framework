<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security\Session;

use Override;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\SessionConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\Handler\CookieHandler;
use Pulsar\Security\Session\Handler\DatabaseHandler;
use Pulsar\Security\Session\Handler\FileHandler;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;
use Pulsar\Security\Session\Validator\RemoteAddressValidator;
use Pulsar\Security\Session\Validator\UserAgentValidator;
use ReflectionClass;

use function random_bytes;
use function sodium_bin2hex;

/**
 * Integration tests verifying session system components working together.
 */
#[CoversClass(SessionManager::class)]
final class SessionIntegrationTest extends TestCase
{
    #[Test]
    public function handlerCapabilityMatrixArray(): void
    {
        $handler = new ArrayHandler();

        self::assertFalse($handler->supportsConcurrencyControl());
        self::assertFalse($handler->supportsSessionListing());
        self::assertFalse($handler->supportsRevocation());
    }

    #[Test]
    public function handlerCapabilityMatrixFile(): void
    {
        $handler = new FileHandler();

        self::assertFalse($handler->supportsConcurrencyControl());
        self::assertFalse($handler->supportsSessionListing());
        self::assertFalse($handler->supportsRevocation());
    }

    #[Test]
    public function handlerCapabilityMatrixDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $handler = new DatabaseHandler($pdo);

        self::assertTrue($handler->supportsConcurrencyControl());
        self::assertTrue($handler->supportsSessionListing());
        self::assertTrue($handler->supportsRevocation());
    }

    #[Test]
    public function handlerCapabilityMatrixCookie(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);
        $config = new SessionConfig(
            cookieName: 'TEST',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'cookie',
        );
        $handler = new CookieHandler($encryption, $config);

        self::assertFalse($handler->supportsConcurrencyControl());
        self::assertFalse($handler->supportsSessionListing());
        self::assertFalse($handler->supportsRevocation());
    }

    #[Test]
    public function sessionManagerWithArrayHandlerAndValidators(): void
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

        $validators = [
            new UserAgentValidator('normalized'),
            new RemoteAddressValidator(mode: 'subnet', ipv4Mask: 24),
        ];

        $manager = new SessionManager($handler, $config, $validators);

        // First request
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Mozilla/5.0 Chrome/120.0.6099.130 Safari/537.36'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.100'],
        );

        $manager->startWithRequest($request);
        $manager->set('theme', 'dark');
        $manager->save();
        $sessionId = $manager->id();

        // Second request from same subnet and similar UA
        $manager2 = new SessionManager($handler, $config, $validators);
        $request2 = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'Mozilla/5.0 Chrome/120.0.6099.200 Safari/537.36'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.200'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        $manager2->startWithRequest($request2);

        self::assertSame('dark', $manager2->get('theme'));
    }

    #[Test]
    public function flashMessagePersistenceAcrossSimulatedRequests(): void
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

        // Request 1: Set a flash message
        $manager1 = new SessionManager($handler, $config);
        $manager1->start();
        $flash1 = new FlashBag($manager1);
        $flash1->set('success', 'Record saved!');
        $manager1->save();
        $sessionId = $manager1->id();

        // Request 2: Flash should be available after age()
        $manager2 = new SessionManager($handler, $config);
        $reflection = new ReflectionClass($manager2);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($manager2, $sessionId);
        $manager2->start();
        $flash2 = new FlashBag($manager2);
        $flash2->age();

        self::assertTrue($flash2->has('success'));
        self::assertSame('Record saved!', $flash2->get('success'));

        // Flash consumed
        self::assertFalse($flash2->has('success'));
        $manager2->save();

        // Request 3: Flash should be gone
        $manager3 = new SessionManager($handler, $config);
        $idProperty->setValue($manager3, $sessionId);
        $manager3->start();
        $flash3 = new FlashBag($manager3);
        $flash3->age();

        self::assertFalse($flash3->has('success'));
    }

    #[Test]
    public function sessionManagerWithDatabaseHandlerFullLifecycle(): void
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
            maxConcurrentSessions: 5,
        );

        $dbHandler = new DatabaseHandler($pdo, 'sessions', 3600);
        $manager = new SessionManager($dbHandler, $config);
        $manager->start();

        // Set data and save
        $manager->set('user_id', 42);
        $manager->set('role', 'admin');
        $manager->save();

        $sessionId = $manager->id();

        // Verify in database
        $stmt = $pdo->prepare('SELECT data FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $data = $stmt->fetchColumn();
        self::assertNotEmpty($data);

        // Destroy
        $manager->destroy();

        $stmt->execute([$sessionId]);
        self::assertFalse($stmt->fetchColumn());
    }

    #[Test]
    public function middlewareFullRequestCycle(): void
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

        $sessionManager = new SessionManager($handler, $config);
        $flashBag = new FlashBag($sessionManager);
        $middleware = new SessionMiddleware($sessionManager, $flashBag);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/submit',
            headers: ['User-Agent' => 'TestBrowser/1.0'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.5'],
        );

        $nextHandler = new class ($sessionManager, $flashBag) implements RequestHandlerInterface {
            public function __construct(
                private readonly SessionManager $sessionManager,
                private readonly FlashBag $flashBag,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Simulate controller logic
                $this->sessionManager->set('form_submitted', true);
                $this->flashBag->set('notice', 'Form submitted successfully');

                return new Response(statusCode: 302, body: '');
            }
        };

        $response = $middleware->process($request, $nextHandler);

        // Verify response
        self::assertSame(302, $response->getStatusCode());

        // Verify session was saved
        $sessionId = $sessionManager->id();
        $raw = $handler->read($sessionId);
        self::assertNotEmpty($raw);
        self::assertStringContainsString('form_submitted', $raw);
    }

    #[Test]
    public function encryptedSessionRoundtripWithManager(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryption = SessionEncryption::fromMasterKey($masterKey);
        $handler = new ArrayHandler();

        $config = new SessionConfig(
            cookieName: 'TEST_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'array',
            encryption: true,
        );

        // Write encrypted session
        $manager = new SessionManager($handler, $config, [], $encryption);
        $manager->start();
        $manager->set('secret', 'encrypted_value');
        $manager->save();

        $sessionId = $manager->id();

        // Raw handler data should be encrypted (not plain serialized)
        $raw = $handler->read($sessionId);
        self::assertNotEmpty($raw);
        self::assertStringNotContainsString('encrypted_value', $raw);

        // Read back with encryption
        $manager2 = new SessionManager($handler, $config, [], $encryption);
        $reflection = new ReflectionClass($manager2);
        $idProperty = $reflection->getProperty('sessionId');
        $idProperty->setValue($manager2, $sessionId);
        $manager2->start();

        self::assertSame('encrypted_value', $manager2->get('secret'));
    }
}
