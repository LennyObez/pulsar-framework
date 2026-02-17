<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Http\Controller;

use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\WebSocket\SignalingHandler;
use Pulsar\Http\JsonResponse;
use Pulsar\Http\RequestInterface;
use Pulsar\Http\ResponseInterface;

use function is_string;

/**
 * REST API controller for WebRTC call initiation and ICE configuration.
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
    public function iceServers(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
        }

        return JsonResponse::create([
            'ice_servers' => $this->signalingHandler->getIceServers(),
        ]);
    }

    /**
     * Get the status of an active call.
     */
    public function callStatus(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');
        $callId = $request->attribute('callId');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
        }

        if (!is_string($callId)) {
            return JsonResponse::create(['error' => 'Missing call ID'], 400);
        }

        $session = $this->signalingHandler->getCallSession($callId);

        if ($session === null) {
            return JsonResponse::create(['error' => 'Call not found'], 404);
        }

        // Only allow participants to check call status
        if ($session->callerId !== $userId && $session->calleeId !== $userId) {
            return JsonResponse::create(['error' => 'Access denied'], 403);
        }

        return JsonResponse::create([
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
