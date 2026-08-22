<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Handler;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\Handler\DatabaseHandler;

#[CoversClass(DatabaseHandler::class)]
final class DatabaseHandlerTest extends TestCase
{
    private PDO $pdo;

    private DatabaseHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
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

        $this->handler = new DatabaseHandler($this->pdo, 'sessions', 7200);
    }

    #[Test]
    public function openReturnsTrue(): void
    {
        self::assertTrue($this->handler->open('', 'TEST_SESSION'));
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        self::assertTrue($this->handler->close());
    }

    #[Test]
    public function readReturnsEmptyForNonexistent(): void
    {
        self::assertSame('', $this->handler->read('nonexistent'));
    }

    #[Test]
    public function writeAndReadRoundtrip(): void
    {
        self::assertTrue($this->handler->write('session-1', 'serialized-data'));
        self::assertSame('serialized-data', $this->handler->read('session-1'));
    }

    #[Test]
    public function writeUpsertsExistingSession(): void
    {
        $this->handler->write('session-1', 'original');
        $this->handler->write('session-1', 'updated');

        self::assertSame('updated', $this->handler->read('session-1'));
    }

    #[Test]
    public function destroyRemovesSession(): void
    {
        $this->handler->write('session-1', 'data');

        self::assertTrue($this->handler->destroy('session-1'));
        self::assertSame('', $this->handler->read('session-1'));
    }

    #[Test]
    public function gcRemovesExpiredSessions(): void
    {
        // Insert an old session directly
        $oldTimestamp = time() - 10000;
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, data, ip_address, user_agent, last_activity, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute(['old-session', null, 'old-data', '', '', $oldTimestamp, $oldTimestamp]);

        // Insert a fresh session
        $this->handler->write('fresh-session', 'fresh-data');

        $deleted = $this->handler->gc(7200);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read('old-session'));
        self::assertSame('fresh-data', $this->handler->read('fresh-session'));
    }

    #[Test]
    public function supportsConcurrencyControl(): void
    {
        self::assertTrue($this->handler->supportsConcurrencyControl());
    }

    #[Test]
    public function supportsSessionListing(): void
    {
        self::assertTrue($this->handler->supportsSessionListing());
    }

    #[Test]
    public function supportsRevocation(): void
    {
        self::assertTrue($this->handler->supportsRevocation());
    }

    #[Test]
    public function getActiveSessionsCountsUserSessions(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Agent');
        $this->handler->write('s1', 'data1');

        $this->handler->setSessionContext('user-1', '10.0.0.2', 'Agent');
        $this->handler->write('s2', 'data2');

        $this->handler->setSessionContext('user-2', '10.0.0.3', 'Agent');
        $this->handler->write('s3', 'data3');

        self::assertSame(2, $this->handler->getActiveSessions('user-1'));
        self::assertSame(1, $this->handler->getActiveSessions('user-2'));
        self::assertSame(0, $this->handler->getActiveSessions('user-3'));
    }

    #[Test]
    public function listSessionsReturnsUserSessions(): void
    {
        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Chrome');
        $this->handler->write('s1', 'data1');

        $this->handler->setSessionContext('user-1', '10.0.0.2', 'Firefox');
        $this->handler->write('s2', 'data2');

        $sessions = $this->handler->listSessions('user-1');

        self::assertCount(2, $sessions);
        $ids = array_column($sessions, 'id');
        self::assertContains('s1', $ids);
        self::assertContains('s2', $ids);

        // Verify structure of returned rows
        foreach ($sessions as $session) {
            self::assertArrayHasKey('id', $session);
            self::assertArrayHasKey('last_activity', $session);
            self::assertArrayHasKey('ip_address', $session);
            self::assertArrayHasKey('user_agent', $session);
            self::assertArrayHasKey('created_at', $session);
        }
    }

    #[Test]
    public function listSessionsExcludesExpired(): void
    {
        // Insert an old session directly
        $oldTimestamp = time() - 10000;
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, data, ip_address, user_agent, last_activity, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute(['old-s', 'user-1', 'data', '', '', $oldTimestamp, $oldTimestamp]);

        $this->handler->setSessionContext('user-1', '10.0.0.1', 'Agent');
        $this->handler->write('new-s', 'data');

        $sessions = $this->handler->listSessions('user-1');

        self::assertCount(1, $sessions);
        self::assertSame('new-s', $sessions[0]['id']);
    }

    #[Test]
    public function revokeSessionRemovesIt(): void
    {
        $this->handler->write('s1', 'data');

        self::assertTrue($this->handler->revokeSession('s1'));
        self::assertSame('', $this->handler->read('s1'));
    }

    #[Test]
    public function revokeNonexistentSessionReturnsFalse(): void
    {
        self::assertFalse($this->handler->revokeSession('nonexistent'));
    }

    #[Test]
    public function sessionContextStoredWithWrite(): void
    {
        $this->handler->setSessionContext('user-42', '192.168.1.1', 'Mozilla/5.0');
        $this->handler->write('s1', 'session-data');

        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute(['s1']);
        /** @var array{user_id: string|null, ip_address: string|null, user_agent: string|null} $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame('user-42', $row['user_id']);
        self::assertSame('192.168.1.1', $row['ip_address']);
        self::assertSame('Mozilla/5.0', $row['user_agent']);
    }

    #[Test]
    public function writeFailsOnPDOExceptionRollsBack(): void
    {
        // Use a fresh PDO that will fail on INSERT due to missing table
        $badPdo = new PDO('sqlite::memory:');
        $badPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Create table without required columns to cause write failure
        $badPdo->exec('CREATE TABLE sessions (id VARCHAR(128) PRIMARY KEY)');

        $handler = new DatabaseHandler($badPdo, 'sessions', 3600);
        self::assertTrue($handler->open('', ''));
        $handler->setSessionContext('u1', '10.0.0.1', 'Agent');

        // write should catch PDOException and return false
        $result = $handler->write('s1', 'data');
        self::assertFalse($result);
    }
}
