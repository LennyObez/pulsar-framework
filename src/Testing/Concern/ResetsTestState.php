<?php

declare(strict_types=1);

namespace Pulsar\Testing\Concern;

use Pulsar\Api\Api;
use Pulsar\Testing\Fake\CacheFake;
use Pulsar\Testing\Fake\EventFake;
use Pulsar\Testing\Fake\MailFake;
use Pulsar\Testing\Fake\NotificationFake;
use Pulsar\Testing\Fake\QueueFake;
use Pulsar\Testing\Fake\StorageFake;

/**
 * Automatically resets all registered fakes after each test case.
 *
 * Use this trait in your TestCase subclass to guarantee no state leaks
 * between tests: even if a test throws an exception.
 *
 * Fakes are scoped to the current test instance (no shared static state),
 * making them safe for parallel test execution.
 */
#[Api(since: '1.0.0')]
trait ResetsTestState
{
    private ?EventFake $eventFake = null;

    private ?QueueFake $queueFake = null;

    private ?MailFake $mailFake = null;

    private ?NotificationFake $notificationFake = null;

    private ?CacheFake $cacheFake = null;

    private ?StorageFake $storageFake = null;

    /**
     * Create and register a fake event dispatcher.
     */
    protected function fakeEvents(): EventFake
    {
        return $this->eventFake = new EventFake();
    }

    /**
     * Create and register a fake queue driver.
     */
    protected function fakeQueue(): QueueFake
    {
        return $this->queueFake = new QueueFake();
    }

    /**
     * Create and register a fake mail manager.
     */
    protected function fakeMail(): MailFake
    {
        return $this->mailFake = new MailFake();
    }

    /**
     * Create and register a fake notification manager.
     */
    protected function fakeNotifications(): NotificationFake
    {
        return $this->notificationFake = new NotificationFake();
    }

    /**
     * Create and register a fake cache driver.
     */
    protected function fakeCache(): CacheFake
    {
        return $this->cacheFake = new CacheFake();
    }

    /**
     * Create and register a fake storage adapter.
     */
    protected function fakeStorage(): StorageFake
    {
        return $this->storageFake = new StorageFake();
    }

    /**
     * Reset all registered fakes.
     *
     * Called automatically via tearDown(): no manual cleanup needed.
     */
    protected function resetTestState(): void
    {
        $this->eventFake?->reset();
        $this->queueFake?->reset();
        $this->mailFake?->reset();
        $this->notificationFake?->reset();
        $this->cacheFake?->reset();
        $this->storageFake?->reset();

        $this->eventFake = null;
        $this->queueFake = null;
        $this->mailFake = null;
        $this->notificationFake = null;
        $this->cacheFake = null;
        $this->storageFake = null;
    }
}
