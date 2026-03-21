<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\WebSocket\BroadcastManagerInterface as WebSocketBroadcastManagerInterface;
use ReflectionClass;
use Throwable;

use function sprintf;

/**
 * Dispatches broadcast events to WebSocket channels.
 *
 * Bridges between the framework's event system and the WebSocket broadcast
 * infrastructure. Resolves channels from the event, applies authorization
 * constraints, and delegates delivery to the WebSocket BroadcastManager.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BroadcastManager
{
    public function __construct(
        private WebSocketBroadcastManagerInterface $transport,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Broadcast an event to its channels.
     */
    public function broadcast(BroadcastEventInterface $event): void
    {
        $channels = $event->broadcastOn();
        $eventName = $event->broadcastAs();
        $data = $event->broadcastWith();
        $excludeIds = $this->resolveExcludedConnections($event);

        foreach ($channels as $channel) {
            $channelName = $channel->channelName();

            try {
                if ($excludeIds !== []) {
                    $this->transport->broadcastExcept($channelName, $eventName, $data, $excludeIds);
                } else {
                    $this->transport->broadcast($channelName, $eventName, $data);
                }

                $this->logger?->debug(sprintf(
                    'Broadcast event "%s" to channel "%s"',
                    $eventName,
                    $channelName,
                ));
            } catch (Throwable $e) {
                $this->logger?->error(sprintf(
                    'Failed to broadcast event "%s" to channel "%s": %s',
                    $eventName,
                    $channelName,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Broadcast raw data to a specific channel.
     *
     * @param array<string, mixed> $data
     */
    public function broadcastTo(Channel $channel, string $event, array $data): void
    {
        $this->transport->broadcast($channel->channelName(), $event, $data);
    }

    /**
     * Send a direct message to a specific connection.
     *
     * @param array<string, mixed> $data
     */
    public function sendToUser(string $connectionId, string $event, array $data): void
    {
        $this->transport->sendTo($connectionId, $event, $data);
    }

    /**
     * Resolve connection IDs to exclude (for #[ShouldBroadcast(toOthers: true)]).
     *
     * @return list<string>
     */
    private function resolveExcludedConnections(BroadcastEventInterface $event): array
    {
        try {
            $reflection = new ReflectionClass($event);
            $attributes = $reflection->getAttributes(ShouldBroadcast::class);

            if ($attributes !== []) {
                $attr = $attributes[0]->newInstance();

                if ($attr->toOthers && $event instanceof ExcludesConnectionInterface) {
                    $id = $event->excludeConnectionId();

                    if ($id !== null) {
                        return [$id];
                    }
                }
            }
        } catch (Throwable) {
            // Reflection failure: don't exclude anyone
        }

        return [];
    }
}
