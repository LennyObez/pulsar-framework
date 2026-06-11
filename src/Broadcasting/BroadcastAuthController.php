<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Pulsar\Api\Api;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;
use Pulsar\WebSocket\ChannelAuthorizerInterface;
use Pulsar\WebSocket\ChannelManager;
use Pulsar\WebSocket\WebSocketConnection;

use function is_string;
use function preg_match;
use function strtolower;

/**
 * HTTP controller that handles channel authentication for private/presence channels.
 *
 * Clients POST to this endpoint with `channel_name` and `socket_id` to get
 * an auth token that allows subscribing to private or presence channels.
 *
 * Private and presence channels require an AUTHENTICATED requester: the
 * identity resolved by the authentication middleware (request attribute
 * `identity`) is attached to the connection handed to the channel authorizer,
 * so authorizers decide with a real principal
 * ({@see WebSocketConnection::userId()}) instead of an anonymous ephemeral
 * connection. Requests without an authenticated identity are denied with 401
 * before the authorizer runs — route this endpoint through the authentication
 * middleware. Public channels remain auth-free.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BroadcastAuthController
{
    /**
     * Conservative allowlist for client-supplied socket ids: 1–64 chars,
     * starting alphanumeric, then alphanumerics plus `. _ : -`. Covers the
     * common transport formats ("123.456", "conn-99") while rejecting control
     * characters, separators, and anything header-/log-injection shaped before
     * the value reaches the authorizer or any log line.
     */
    private const string SOCKET_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/';

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

        if (preg_match(self::SOCKET_ID_PATTERN, $socketId) !== 1) {
            return Response::json(
                ['error' => 'Invalid socket_id format'],
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

        $isPresence = ChannelManager::isPresenceChannel($channelName);
        $isPrivate = !$isPresence && ChannelManager::isPrivateChannel($channelName);

        $connection = new WebSocketConnection($socketId, (float) time());

        if ($isPresence || $isPrivate) {
            // Deny-by-default: a private/presence subscription is an
            // identity-bound grant, so an unauthenticated requester is
            // rejected before the authorizer ever runs.
            $identity = $request->attribute('identity');

            if (!$identity instanceof IdentityInterface || !$identity->isAuthenticated()) {
                return Response::json(['error' => 'Unauthenticated'], 401);
            }

            // The authorizer decides with the real principal: userId() carries
            // the authenticated identity, not a client-asserted value.
            $connection->authenticate($identity->id());
        }

        if ($isPresence) {
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

        if ($isPrivate) {
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
