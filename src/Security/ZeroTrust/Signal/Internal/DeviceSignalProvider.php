<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal\Internal;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceRegistryInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

use function is_string;

/**
 * Produces device-related trust claims.
 *
 * Checks the current request for device registration, known device cookies,
 * and attestation status. Degrades gracefully when the device registry is unavailable.
 *
 * Claims produced:
 * - `device.known` (bool): Whether a device cookie is present
 * - `device.registered` (bool): Whether the device is in the registry
 * - `device.attestation_valid` (bool): Whether attestation was verified
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class DeviceSignalProvider implements SignalProviderInterface
{
    private const float CONFIDENCE_REGISTERED_WITH_COOKIE = 0.95;
    private const float CONFIDENCE_COOKIE_ONLY = 0.7;
    private const float CONFIDENCE_NO_DEVICE_INFO = 0.1;

    public function __construct(
        private ?DeviceRegistryInterface $deviceRegistry = null,
    ) {}

    public function evaluate(SignalContext $context): ClaimSet
    {
        $now = new DateTimeImmutable();
        $deviceCookie = $this->extractDeviceCookie($context);
        $hasDeviceCookie = $deviceCookie !== null;
        $isRegistered = false;
        $attestationValid = false;

        if ($hasDeviceCookie && $this->deviceRegistry !== null) {
            $device = $this->deviceRegistry->find($deviceCookie);

            if ($device !== null) {
                $isRegistered = true;
                $attestationValid = $device->lastVerifiedAt !== null;
            }
        }

        $confidence = $this->resolveConfidence($hasDeviceCookie, $isRegistered);

        return new ClaimSet([
            new Claim(
                name: 'device.known',
                value: $hasDeviceCookie,
                source: ClaimSource::DeviceSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'device.registered',
                value: $isRegistered,
                source: ClaimSource::DeviceSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'device.attestation_valid',
                value: $attestationValid,
                source: ClaimSource::DeviceSignal,
                confidence: $isRegistered ? $confidence : self::CONFIDENCE_NO_DEVICE_INFO,
                timestamp: $now,
            ),
        ]);
    }

    public function name(): string
    {
        return 'device';
    }

    private function extractDeviceCookie(SignalContext $context): ?string
    {
        $cookies = $context->request->getCookieParams();
        $deviceId = isset($cookies['_pulsar_device_id']) && is_string($cookies['_pulsar_device_id'])
            ? $cookies['_pulsar_device_id']
            : null;

        if ($deviceId === null || $deviceId === '') {
            /** @var string|null $headerDeviceId */
            $headerDeviceId = $context->attribute('device_id');

            return $headerDeviceId !== null && $headerDeviceId !== '' ? $headerDeviceId : null;
        }

        return $deviceId;
    }

    private function resolveConfidence(bool $hasCookie, bool $isRegistered): float
    {
        if ($isRegistered && $hasCookie) {
            return self::CONFIDENCE_REGISTERED_WITH_COOKIE;
        }

        if ($hasCookie) {
            return self::CONFIDENCE_COOKIE_ONLY;
        }

        return self::CONFIDENCE_NO_DEVICE_INFO;
    }
}
