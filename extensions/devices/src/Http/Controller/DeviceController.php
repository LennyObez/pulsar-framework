<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function is_string;

/**
 * REST API controller for device management.
 *
 * Provides CRUD operations for user devices plus token rotation.
 * All endpoints require an authenticated user (user_id request attribute).
 */
#[Internal(reason: 'Devices HTTP controller; implementation detail')]
final readonly class DeviceController
{
    public function __construct(
        private DeviceService $deviceService,
    ) {}

    /**
     * GET /api/v1/devices: List the authenticated user's devices.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        /** @var string $userId */
        $userId = $request->getAttribute('user_id', '');

        if ($userId === '') {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $devices = $this->deviceService->listDevices($userId);

        return Response::json([
            'data' => array_map(self::serializeDevice(...), $devices),
        ]);
    }

    /**
     * POST /api/v1/devices: Register a new device and return the raw API token once.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function register(ServerRequestInterface $request): Response
    {
        /** @var string $userId */
        $userId = $request->getAttribute('user_id', '');

        if ($userId === '') {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawDeviceName */
        $rawDeviceName = $body['device_name'] ?? null;
        $deviceName = is_string($rawDeviceName) ? $rawDeviceName : '';
        /** @var mixed $rawPlatform */
        $rawPlatform = $body['platform'] ?? null;
        $platform = is_string($rawPlatform) ? $rawPlatform : '';
        /** @var mixed $rawAppVersion */
        $rawAppVersion = $body['app_version'] ?? null;
        $appVersion = is_string($rawAppVersion) ? $rawAppVersion : '';

        if ($deviceName === '' || $platform === '' || $appVersion === '') {
            return Response::json([
                'error' => 'Validation failed',
                'details' => [
                    'device_name' => $deviceName === '' ? 'Device name is required' : null,
                    'platform' => $platform === '' ? 'Platform is required' : null,
                    'app_version' => $appVersion === '' ? 'App version is required' : null,
                ],
            ], 422);
        }

        $platformEnum = Platform::tryFrom($platform);

        if ($platformEnum === null) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['platform' => 'Invalid platform. Must be one of: android, ios, web'],
            ], 422);
        }

        try {
            $result = $this->deviceService->register($userId, $deviceName, $platformEnum, $appVersion);

            return Response::json([
                'data' => self::serializeDevice($result['device']),
                'token' => $result['token'],
            ], 201);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/devices/{id}/rotate: Rotate the API token for a device.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function rotate(ServerRequestInterface $request, string $id): Response
    {
        /** @var string $userId */
        $userId = $request->getAttribute('user_id', '');

        if ($userId === '') {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        try {
            $result = $this->deviceService->rotateToken($id, $userId);

            return Response::json([
                'data' => self::serializeDevice($result['device']),
                'token' => $result['token'],
            ]);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * DELETE /api/v1/devices/{id}: Remove a device registration.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        /** @var string $userId */
        $userId = $request->getAttribute('user_id', '');

        if ($userId === '') {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        try {
            $this->deviceService->remove($id, $userId);

            return Response::json(['data' => ['id' => $id, 'deleted' => true]]);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Serialize a UserDevice to an API-safe array.
     *
     * @return array<string, mixed>
     */
    private static function serializeDevice(UserDevice $device): array
    {
        return [
            'id' => $device->id,
            'user_id' => $device->userId,
            'device_name' => $device->deviceName,
            'platform' => $device->platform->value,
            'app_version' => $device->appVersion,
            'last_seen_at' => $device->lastSeenAt?->format('c'),
            'created_at' => $device->createdAt->format('c'),
        ];
    }
}
