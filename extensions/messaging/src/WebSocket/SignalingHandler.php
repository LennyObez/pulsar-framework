<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebSocket;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Domain\CallStatus;
use Pulsar\Extension\Messaging\WebRTC\CallSession;
use Pulsar\Extension\Messaging\WebRTC\SignalingMessage;
use Pulsar\Extension\Messaging\WebRTC\WebRtcConfig;
use Pulsar\WebSocket\BroadcastManagerInterface;

use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * WebRTC signaling handler for offer/answer/ICE candidate exchange.
 *
 * Relays signaling messages between peers via WebSocket. The server
 * does not see or process media streams: only signaling metadata
 * passes through. Media keys are exchanged via the E2EE channel.
 * @api
 */
#[Api(since: '1.0.0')]
final class SignalingHandler
{
    /** @var array<string, CallSession> Active call sessions by call ID */
    private array $activeCalls = [];

    /** @var array<string, string> User ID => WebSocket connection ID mapping */
    private array $userConnections = [];

    public function __construct(
        private readonly BroadcastManagerInterface $broadcastManager,
        private readonly WebRtcConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Register a user's WebSocket connection for signaling.
     */
    public function registerConnection(string $userId, string $connectionId): void
    {
        $this->userConnections[$userId] = $connectionId;
    }

    /**
     * Unregister a user's connection (on disconnect).
     */
    public function unregisterConnection(string $userId): void
    {
        unset($this->userConnections[$userId]);

        // End any active calls this user was in
        foreach ($this->activeCalls as $callId => $session) {
            if ($session->callerId === $userId || $session->calleeId === $userId) {
                $session->end();
                $this->notifyCallEnded($session);
                unset($this->activeCalls[$callId]);
            }
        }
    }

    /**
     * Handle an incoming signaling message.
     */
    public function handleSignaling(string $userId, string $rawPayload): void
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $message = SignalingMessage::fromArray([
            'type' => $data['type'] ?? '',
            'from_user_id' => $userId,
            'to_user_id' => $data['to_user_id'] ?? '',
            'call_id' => $data['call_id'] ?? '',
            'payload' => is_array($data['payload'] ?? null) ? $data['payload'] : [],
        ]);

        if ($message->toUserId === '') {
            return;
        }

        match (true) {
            $message->isOffer() => $this->handleOffer($message),
            $message->isAnswer() => $this->handleAnswer($message),
            $message->isIceCandidate() => $this->handleIceCandidate($message),
            $message->isBye() => $this->handleBye($message),
            default => null,
        };
    }

    /**
     * Get the active call session for a call ID.
     */
    public function getCallSession(string $callId): ?CallSession
    {
        return $this->activeCalls[$callId] ?? null;
    }

    /**
     * Get the ICE server configuration for clients.
     *
     * @return list<array{urls: string, username?: string, credential?: string}>
     */
    public function getIceServers(): array
    {
        return $this->config->iceServers;
    }

    private function handleOffer(SignalingMessage $message): void
    {
        $session = new CallSession(
            id: $message->callId,
            callerId: $message->fromUserId,
            calleeId: $message->toUserId,
            conversationId: (string) ($message->payload['conversation_id'] ?? ''),
            status: CallStatus::Ringing,
            startedAt: new DateTimeImmutable(),
        );

        $this->activeCalls[$message->callId] = $session;

        $this->relayToUser($message->toUserId, 'signaling', $message->toArray());

        $this->logger->info('WebRTC call initiated', [
            'call_id' => $message->callId,
            'caller' => $message->fromUserId,
            'callee' => $message->toUserId,
        ]);
    }

    private function handleAnswer(SignalingMessage $message): void
    {
        $session = $this->activeCalls[$message->callId] ?? null;

        if ($session !== null) {
            $session->answer();
        }

        $this->relayToUser($message->toUserId, 'signaling', $message->toArray());
    }

    private function handleIceCandidate(SignalingMessage $message): void
    {
        $this->relayToUser($message->toUserId, 'signaling', $message->toArray());
    }

    private function handleBye(SignalingMessage $message): void
    {
        $session = $this->activeCalls[$message->callId] ?? null;

        if ($session !== null) {
            $session->end();
            unset($this->activeCalls[$message->callId]);
        }

        $this->relayToUser($message->toUserId, 'signaling', $message->toArray());

        $this->logger->info('WebRTC call ended', [
            'call_id' => $message->callId,
            'ended_by' => $message->fromUserId,
        ]);
    }

    private function notifyCallEnded(CallSession $session): void
    {
        $otherUserId = $session->callerId;

        // Determine who is still connected
        if (isset($this->userConnections[$session->callerId])) {
            $otherUserId = $session->callerId;
        } elseif (isset($this->userConnections[$session->calleeId])) {
            $otherUserId = $session->calleeId;
        }

        $this->relayToUser($otherUserId, 'signaling', [
            'type' => 'bye',
            'call_id' => $session->id,
            'reason' => 'peer_disconnected',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function relayToUser(string $userId, string $event, array $data): void
    {
        $connectionId = $this->userConnections[$userId] ?? null;

        if ($connectionId === null) {
            return;
        }

        $this->broadcastManager->sendTo($connectionId, $event, $data);
    }
}
