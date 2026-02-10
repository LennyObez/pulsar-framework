<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Flash;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;

#[CoversClass(FlashBag::class)]
final class FlashBagTest extends TestCase
{
    private SessionManager $session;

    private FlashBag $flash;

    protected function setUp(): void
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

        $this->session = new SessionManager($handler, $config);
        $this->session->start();

        $this->flash = new FlashBag($this->session);
    }

    #[Test]
    public function setAndGetAfterAge(): void
    {
        $this->flash->set('message', 'Hello World');

        // Age rotates new → old
        $this->flash->age();

        self::assertSame('Hello World', $this->flash->get('message'));
    }

    #[Test]
    public function getConsumesValue(): void
    {
        $this->flash->set('message', 'Consumed');
        $this->flash->age();

        self::assertSame('Consumed', $this->flash->get('message'));
        // Second get should return default since it was consumed
        self::assertNull($this->flash->get('message'));
    }

    #[Test]
    public function hasReturnsTrueForExistingFlash(): void
    {
        $this->flash->set('notice', 'Important');
        $this->flash->age();

        self::assertTrue($this->flash->has('notice'));
    }

    #[Test]
    public function hasReturnsFalseAfterConsumption(): void
    {
        $this->flash->set('notice', 'Temporary');
        $this->flash->age();

        // Consume the value
        $this->flash->get('notice');

        self::assertFalse($this->flash->has('notice'));
    }

    #[Test]
    public function peekDoesNotConsume(): void
    {
        $this->flash->set('message', 'Peeked');
        $this->flash->age();

        self::assertSame('Peeked', $this->flash->peek('message'));
        // Value should still be available after peek
        self::assertSame('Peeked', $this->flash->get('message'));
    }

    #[Test]
    public function keepReflashesValue(): void
    {
        $this->flash->set('message', 'Kept');
        $this->flash->age();

        // Keep the value for another cycle
        $this->flash->keep('message');

        // Age again — the kept value should move from new → old
        $this->flash->age();

        self::assertSame('Kept', $this->flash->get('message'));
    }

    #[Test]
    public function allReturnsAndClears(): void
    {
        $this->flash->set('key1', 'value1');
        $this->flash->set('key2', 'value2');
        $this->flash->age();

        $all = $this->flash->all();

        self::assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
        ], $all);

        // Bag should be empty after all()
        self::assertFalse($this->flash->has('key1'));
        self::assertFalse($this->flash->has('key2'));
    }

    #[Test]
    public function clearRemovesEverything(): void
    {
        $this->flash->set('new_key', 'new_value');
        $this->flash->age();
        $this->flash->set('another', 'value');

        $this->flash->clear();

        // Both old and new bags should be empty
        self::assertFalse($this->flash->has('new_key'));
        $this->flash->age();
        self::assertFalse($this->flash->has('another'));
    }

    #[Test]
    public function flashPersistsForExactlyOneRead(): void
    {
        // Set a flash message
        $this->flash->set('ephemeral', 'one-time');

        // Age (simulates request boundary)
        $this->flash->age();

        // First read: value available
        self::assertSame('one-time', $this->flash->get('ephemeral'));

        // Second read: consumed, returns default
        self::assertNull($this->flash->get('ephemeral'));
    }

    #[Test]
    public function getReturnsDefaultWhenKeyMissing(): void
    {
        $this->flash->age();

        self::assertNull($this->flash->get('nonexistent'));
        self::assertSame('fallback', $this->flash->get('nonexistent', 'fallback'));
    }

    #[Test]
    public function flashNotAvailableBeforeAge(): void
    {
        $this->flash->set('message', 'Not yet');

        // Without age(), the value is in 'new' bag but not in 'old' bag
        self::assertFalse($this->flash->has('message'));
    }
}
