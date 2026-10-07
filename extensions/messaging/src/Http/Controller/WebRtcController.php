<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\WebSocket\SignalingHandler;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * REST API controller for WebRTC call initiation and ICE configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebRtcController
{
    public function __construct(
        private SignalingHandler $signalingHandler,
    ) {}

    /**
     * Get ICE server configuration for the client.
     */
    public function iceServers(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        return Response::json([
            'ice_servers' => $this->signalingHandler->getIceServers(),
        ]);
    }

    /**
     * Get the status of an active call.
     */
    public function callStatus(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');
        /** @var mixed $callId */
        $callId = $request->getAttribute('callId');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        if (!is_string($callId)) {
            return Response::json(['error' => 'Missing call ID'], 400);
        }

        $session = $this->signalingHandler->getCallSession($callId);

        if ($session === null) {
            return Response::json(['error' => 'Call not found'], 404);
        }

        // Only allow participants to check call status
        if ($session->callerId !== $userId && $session->calleeId !== $userId) {
            return Response::json(['error' => 'Access denied'], 403);
        }

        return Response::json([
            'id' => $session->id,
            'caller_id' => $session->callerId,
            'callee_id' => $session->calleeId,
            'status' => $session->status->value,
            'started_at' => $session->startedAt->format('c'),
            'answered_at' => $session->answeredAt?->format('c'),
            'duration_seconds' => $session->durationSeconds(),
        ]);
    }
}
