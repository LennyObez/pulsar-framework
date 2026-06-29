<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;
use Pulsar\WebSocket\ChannelAuthorizerInterface;
use Pulsar\WebSocket\ChannelManager;
use Pulsar\WebSocket\WebSocketConnection;

use function is_string;
use function strtolower;

/**
 * HTTP controller that handles channel authentication for private/presence channels.
 *
 * Clients POST to this endpoint with `channel_name` and `socket_id` to get
 * an auth token that allows subscribing to private or presence channels.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BroadcastAuthController
{
    public function __construct(
        private ChannelAuthorizerInterface $authorizer,
    ) {}

    /**
     * Handle a channel auth request.
     *
     * @param Request $request Must contain 'channel_name' and 'socket_id' fields
     */
    public function authenticate(Request $request): Response
    {
        $channelName = $request->post('channel_name');
        $socketId = $request->post('socket_id');

        if (!is_string($channelName) || !is_string($socketId) || $channelName === '' || $socketId === '') {
            return Response::json(
                ['error' => 'Missing channel_name or socket_id'],
                400,
            );
        }

        // Channel-type detection is prefix-based and case-sensitive
        // (ChannelManager::isPrivateChannel / isPresenceChannel). A mixed-case
        // prefix such as "Private-orders" would otherwise bypass the prefix
        // checks and be authorized as a public channel. Reject any non-lowercase
        // channel name rather than silently accepting an ambiguous one.
        if ($channelName !== strtolower($channelName)) {
            return Response::json(
                ['error' => 'Invalid channel_name: must be lowercase'],
                400,
            );
        }

        $connection = new WebSocketConnection($socketId, (float) time());

        if (ChannelManager::isPresenceChannel($channelName)) {
            $userInfo = $this->authorizer->authorizePresence($channelName, $connection);

            if ($userInfo === null) {
                return Response::json(['error' => 'Forbidden'], 403);
            }

            return Response::json([
                'channel' => $channelName,
                'auth' => true,
                'user_info' => $userInfo,
            ]);
        }

        if (ChannelManager::isPrivateChannel($channelName)) {
            $authorized = $this->authorizer->authorizePrivate($channelName, $connection);

            if (!$authorized) {
                return Response::json(['error' => 'Forbidden'], 403);
            }

            return Response::json(['channel' => $channelName, 'auth' => true]);
        }

        // Public channels don't require auth
        return Response::json(['channel' => $channelName, 'auth' => true]);
    }
}
