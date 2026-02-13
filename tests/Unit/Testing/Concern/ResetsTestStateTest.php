<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Concern;

use PHPUnit\Framework\Attributes\Test;
use Pulsar\Testing\TestCase;
use stdClass;

final class ResetsTestStateTest extends TestCase
{
    #[Test]
    public function fake_events_creates_event_fake(): void
    {
        $fake = $this->fakeEvents();

        $fake->dispatch(new stdClass());

        $fake->assertDispatched(stdClass::class);
    }

    #[Test]
    public function fake_queue_creates_queue_fake(): void
    {
        $fake = $this->fakeQueue();

        $fake->push('default', 'App\Jobs\Test', '{}');

        $fake->assertPushed('App\Jobs\Test');
    }

    #[Test]
    public function fake_mail_creates_mail_fake(): void
    {
        $fake = $this->fakeMail();

        $fake->assertNothingSent();
    }

    #[Test]
    public function fake_notifications_creates_notification_fake(): void
    {
        $fake = $this->fakeNotifications();

        $fake->assertNothingSent();
    }

    #[Test]
    public function fake_cache_creates_cache_fake(): void
    {
        $fake = $this->fakeCache();

        $fake->set('key', 'value', null);
        $fake->assertHas('key');
    }

    #[Test]
    public function fake_storage_creates_storage_fake(): void
    {
        $fake = $this->fakeStorage();

        $fake->put('file.txt', 'content');
        $fake->assertExists('file.txt');
    }

    #[Test]
    public function state_is_isolated_between_tests_first(): void
    {
        $events = $this->fakeEvents();

        $events->dispatch(new stdClass());

        // This test dispatches an event — the next test verifies the state is clean
        $events->assertDispatched(stdClass::class);
    }

    #[Test]
    public function state_is_isolated_between_tests_second(): void
    {
        // Because tearDown resets all fakes, a fresh fakeEvents() here
        // should have no previously dispatched events
        $events = $this->fakeEvents();

        $events->assertNothingDispatched();
    }
}
