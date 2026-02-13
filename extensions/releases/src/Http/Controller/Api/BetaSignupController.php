<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Http\Controller\Api;

use InvalidArgumentException;
use OverflowException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Releases\DeviceType;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Http\Message\Response;

use function array_filter;
use function array_values;
use function htmlspecialchars;
use function is_array;
use function is_string;

use const ENT_QUOTES;

/**
 * Public API controller for beta program signups.
 *
 * Validates input, enforces rate limits, and registers new beta
 * participants via the release service.
 */
#[Internal(reason: 'Beta signup HTTP controller — implementation detail')]
final readonly class BetaSignupController
{
    public function __construct(
        private ReleaseService $service,
    ) {}

    /**
     * POST /api/v1/beta/signup — Register for the beta program.
     *
     * Request body:
     * - email: string (required, valid email)
     * - device_type: string (required, one of: android, ios, both)
     * - camera_brands: array of strings (optional)
     *
     * Returns 201 on success, 422 on validation error, 429 on rate limit.
     */
    public function signup(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        // Validate email
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';

        if ($email === '') {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['email' => 'Email address is required'],
            ], 422);
        }

        // Validate device_type
        $deviceTypeValue = is_string($body['device_type'] ?? null) ? $body['device_type'] : '';
        $deviceType = DeviceType::tryFrom($deviceTypeValue);

        if ($deviceType === null) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => [
                    'device_type' => 'Invalid device type. Must be one of: android, ios, both',
                ],
            ], 422);
        }

        // Extract camera_brands
        /** @var list<string> $cameraBrands */
        $cameraBrands = [];
        $rawBrands = $body['camera_brands'] ?? null;

        if (is_array($rawBrands)) {
            $cameraBrands = array_values(array_filter(
                $rawBrands,
                static fn(mixed $brand): bool => is_string($brand) && $brand !== '',
            ));
        }

        try {
            $signup = $this->service->signupForBeta($email, $deviceType, $cameraBrands);

            return Response::json([
                'data' => [
                    'id' => $signup->id,
                    'email' => htmlspecialchars($signup->email, ENT_QUOTES, 'UTF-8'),
                    'device_type' => $signup->deviceType->value,
                    'camera_brands' => $signup->cameraBrands,
                    'signed_up_at' => $signup->signedUpAt->format('c'),
                ],
            ], 201);
        } catch (OverflowException $e) {
            return Response::json(['error' => $e->getMessage()], 429);
        } catch (InvalidArgumentException $e) {
            return Response::json([
                'error' => 'Validation failed',
                'details' => ['email' => $e->getMessage()],
            ], 422);
        }
    }
}
