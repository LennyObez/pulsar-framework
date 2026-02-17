<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Broadcasting\BroadcastEventInterface;
use Pulsar\Broadcasting\BroadcastManager;
use Pulsar\Broadcasting\Channel;
use Pulsar\Broadcasting\ExcludesConnectionInterface;
use Pulsar\Broadcasting\ShouldBroadcast;
use Pulsar\WebSocket\BroadcastManagerInterface as Transport;

#[CoversClass(BroadcastManager::class)]
final class BroadcastManagerExcludeTest extends TestCase
{
    #[Test]
    public function broadcastExcludesSenderWhenToOthersEnabled(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())
            ->method('broadcastExcept')
            ->with('notifications', 'alert', ['msg' => 'hi'], ['conn-99']);

        $manager = new BroadcastManager($transport);

        $event = new ExcludingSenderEvent();
        $manager->broadcast($event);
    }

    #[Test]
    public function broadcastDoesNotExcludeWhenToOthersDisabled(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())
            ->method('broadcast')
            ->with('notifications', 'alert', ['msg' => 'hi']);
        $transport->expects(self::never())->method('broadcastExcept');

        $manager = new BroadcastManager($transport);

        $event = new NonExcludingEvent();
        $manager->broadcast($event);
    }

    #[Test]
    public function broadcastDoesNotExcludeWhenConnectionIdIsNull(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())
            ->method('broadcast')
            ->with('notifications', 'alert', ['msg' => 'hi']);
        $transport->expects(self::never())->method('broadcastExcept');

        $manager = new BroadcastManager($transport);

        $event = new NullConnectionExcludeEvent();
        $manager->broadcast($event);
    }

    #[Test]
    public function broadcastLogsDebugOnSuccess(): void
    {
        $transport = $this->createStub(Transport::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('Broadcast event "alert" to channel "notifications"'));

        $manager = new BroadcastManager($transport, $logger);

        $event = new NonExcludingEvent();
        $manager->broadcast($event);
    }

    #[Test]
    public function broadcastWithNoAttributeDoesNotExclude(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())->method('broadcast');
        $transport->expects(self::never())->method('broadcastExcept');

        $manager = new BroadcastManager($transport);

        $event = new class implements BroadcastEventInterface {
            public function broadcastOn(): array
            {
                return [new Channel('test')];
            }

            public function broadcastAs(): string
            {
                return 'test.event';
            }

            public function broadcastWith(): array
            {
                return [];
            }
        };

        $manager->broadcast($event);
    }
}

/**
 * Event with #[ShouldBroadcast(toOthers: true)] and ExcludesConnectionInterface.
 *
 * @internal
 */
#[ShouldBroadcast(toOthers: true)]
final class ExcludingSenderEvent implements BroadcastEventInterface, ExcludesConnectionInterface
{
    public function broadcastOn(): array
    {
        return [new Channel('notifications')];
    }

    public function broadcastAs(): string
    {
        return 'alert';
    }

    public function broadcastWith(): array
    {
        return ['msg' => 'hi'];
    }

    public function excludeConnectionId(): ?string
    {
        return 'conn-99';
    }
}

/**
 * Event with #[ShouldBroadcast] but without toOthers.
 *
 * @internal
 */
#[ShouldBroadcast]
final class NonExcludingEvent implements BroadcastEventInterface
{
    public function broadcastOn(): array
    {
        return [new Channel('notifications')];
    }

    public function broadcastAs(): string
    {
        return 'alert';
    }

    public function broadcastWith(): array
    {
        return ['msg' => 'hi'];
    }
}

/**
 * Event with toOthers but null connection ID.
 *
 * @internal
 */
#[ShouldBroadcast(toOthers: true)]
final class NullConnectionExcludeEvent implements BroadcastEventInterface, ExcludesConnectionInterface
{
    public function broadcastOn(): array
    {
        return [new Channel('notifications')];
    }

    public function broadcastAs(): string
    {
        return 'alert';
    }

    public function broadcastWith(): array
    {
        return ['msg' => 'hi'];
    }

    public function excludeConnectionId(): ?string
    {
        return null;
    }
}
