<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Notification\NotificationPreference;
use Pulsar\Extension\Forum\Notification\NotificationPreferenceRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Controller for user notification preferences management.
 */
#[Internal(reason: 'Forum controller; implementation detail')]
final readonly class NotificationPreferencesController
{
    private const array VALID_FREQUENCIES = ['immediate', 'daily', 'weekly'];

    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferenceRepository,
    ) {}

    /**
     * GET /forum/settings/notifications: List current user's notification preferences.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $preferences = $this->preferenceRepository->findByUser($identity->id());

        $items = array_map(static fn(NotificationPreference $p): array => [
            'event_type' => $p->eventType,
            'in_app' => $p->inApp,
            'email' => $p->email,
            'email_frequency' => $p->emailFrequency,
        ], $preferences);

        return Response::json(['data' => $items]);
    }

    /**
     * PUT /forum/settings/notifications: Batch-update notification preferences.
     *
     * Expects a JSON body:
     * ```json
     * {
     *   "preferences": [
     *     {"event_type": "thread_reply", "in_app": true, "email": false, "email_frequency": "immediate"},
     *     ...
     *   ]
     * }
     * ```
     */
    public function update(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        if (!isset($body['preferences']) || !is_array($body['preferences'])) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['preferences' => 'Must be an array of preference objects'],
            ], 422);
        }

        $saved = [];

        /** @var mixed $entry */
        foreach ($body['preferences'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $item = $entry;

            $eventType = is_string($item['event_type'] ?? null) ? $item['event_type'] : null;

            if ($eventType === null || $eventType === '') {
                continue;
            }

            $inApp = is_bool($item['in_app'] ?? null) ? $item['in_app'] : true;
            $email = is_bool($item['email'] ?? null) ? $item['email'] : false;
            $emailFrequency = is_string($item['email_frequency'] ?? null) ? $item['email_frequency'] : 'immediate';

            if (!in_array($emailFrequency, self::VALID_FREQUENCIES, true)) {
                $emailFrequency = 'immediate';
            }

            $existing = $this->preferenceRepository->findByUserAndType($identity->id(), $eventType);

            if ($existing !== null) {
                $preference = $existing->update($inApp, $email, $emailFrequency);
            } else {
                $preference = NotificationPreference::create(
                    $identity->id(),
                    $eventType,
                    $inApp,
                    $email,
                    $emailFrequency,
                );
            }

            $this->preferenceRepository->save($preference);

            $saved[] = [
                'event_type' => $preference->eventType,
                'in_app' => $preference->inApp,
                'email' => $preference->email,
                'email_frequency' => $preference->emailFrequency,
            ];
        }

        return Response::json(['data' => $saved]);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw ForumException::unauthorized('authentication_required');
        }

        return $identity;
    }
}
