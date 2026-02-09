<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Domain\DeviceInfo;
use Pulsar\Extension\Analytics\Domain\DeviceType;

use function preg_match;

/**
 * Lightweight regex-based user agent parser.
 *
 * Detects browser, OS, and device type from user agent strings without
 * external dependencies. Covers the major browsers and platforms that
 * represent 95%+ of real-world traffic.
 */
#[Internal(reason: 'UA parsing internals — use via service binding')]
final readonly class UserAgentParser
{
    /**
     * Parse a user agent string into structured device information.
     */
    public function parse(string $userAgent): DeviceInfo
    {
        if ($userAgent === '') {
            return DeviceInfo::unknown();
        }

        $browser = $this->detectBrowser($userAgent);
        $os = $this->detectOs($userAgent);
        $deviceType = $this->detectDeviceType($userAgent);

        // If we couldn't detect anything meaningful, return unknown
        if ($browser === ['Unknown', ''] && $os === ['Unknown', '']) {
            return DeviceInfo::unknown();
        }

        return new DeviceInfo(
            browser: $browser[0],
            browserVersion: $browser[1],
            os: $os[0],
            osVersion: $os[1],
            deviceType: $deviceType,
        );
    }

    /**
     * Detect browser name and version.
     *
     * @return array{string, string} [name, version]
     */
    private function detectBrowser(string $ua): array
    {
        // Order matters: more specific patterns must come before generic ones.
        // Edge must be checked before Chrome (Edge UA contains "Chrome").
        // Samsung Internet must be checked before Chrome (contains "Chrome").
        // Chrome must be checked before Safari (Chrome UA contains "Safari").

        if (preg_match('/SamsungBrowser\/(\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Samsung Internet', $m[1]];
        }

        if (preg_match('/Edg(?:e|A|iOS)?\/(\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Edge', $m[1]];
        }

        if (preg_match('/OPR\/(\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Opera', $m[1]];
        }

        if (preg_match('/(?:Firefox|FxiOS)\/(\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Firefox', $m[1]];
        }

        // Chrome must be before Safari — Chrome includes "Safari" in its UA
        if (preg_match('/(?:Chrome|CriOS)\/(\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Chrome', $m[1]];
        }

        if (preg_match('/Version\/(\d+[\.\d]*).*Safari/', $ua, $m) === 1) {
            return ['Safari', $m[1]];
        }

        // Fallback: Safari without Version (older iOS)
        if (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) {
            return ['Safari', ''];
        }

        return ['Unknown', ''];
    }

    /**
     * Detect operating system name and version.
     *
     * @return array{string, string} [name, version]
     */
    private function detectOs(string $ua): array
    {
        // iOS detection (must be before macOS — iPad can spoof desktop Safari)
        if (preg_match('/(?:iPhone|iPod).*OS (\d+[_\.\d]*)/', $ua, $m) === 1) {
            return ['iOS', str_replace('_', '.', $m[1])];
        }

        if (preg_match('/iPad.*OS (\d+[_\.\d]*)/', $ua, $m) === 1) {
            return ['iOS', str_replace('_', '.', $m[1])];
        }

        // Android
        if (preg_match('/Android (\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Android', $m[1]];
        }

        // Windows
        if (preg_match('/Windows NT (\d+[\.\d]*)/', $ua, $m) === 1) {
            return ['Windows', $this->mapWindowsVersion($m[1])];
        }

        // macOS
        if (preg_match('/Mac OS X (\d+[_\.\d]*)/', $ua, $m) === 1) {
            return ['macOS', str_replace('_', '.', $m[1])];
        }

        // Linux (generic)
        if (str_contains($ua, 'Linux') && !str_contains($ua, 'Android')) {
            return ['Linux', ''];
        }

        return ['Unknown', ''];
    }

    /**
     * Detect device type from user agent.
     */
    private function detectDeviceType(string $ua): DeviceType
    {
        // Tablet detection (must be before mobile — tablets may also match mobile patterns)
        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $ua) === 1) {
            return DeviceType::Tablet;
        }

        // Mobile detection
        if (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua) === 1) {
            return DeviceType::Mobile;
        }

        // Everything else is desktop
        if (preg_match('/Windows NT|Macintosh|Mac OS X|Linux|CrOS/i', $ua) === 1) {
            return DeviceType::Desktop;
        }

        return DeviceType::Unknown;
    }

    /**
     * Map Windows NT version numbers to marketing names.
     */
    private function mapWindowsVersion(string $ntVersion): string
    {
        return match ($ntVersion) {
            '10.0' => '10',
            '6.3' => '8.1',
            '6.2' => '8',
            '6.1' => '7',
            '6.0' => 'Vista',
            '5.1', '5.2' => 'XP',
            default => $ntVersion,
        };
    }
}
