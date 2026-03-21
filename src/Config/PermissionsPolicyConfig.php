<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function implode;
use function is_array;
use function is_string;

/**
 * Typed configuration DTO for the Permissions-Policy HTTP header.
 *
 * Controls which browser features are allowed. Each property is the
 * allowlist value for the corresponding feature. Use '()' to deny all,
 * '(self)' for same-origin only, or '(self "https://example.com")' for
 * specific origins.
 *
 * @see https://w3c.github.io/webappsec-permissions-policy/
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PermissionsPolicyConfig
{
    /**
     * @param array<string, string> $additional Extra feature => allowlist pairs
     */
    public function __construct(
        public string $camera = '()',
        public string $microphone = '()',
        public string $geolocation = '()',
        public string $accelerometer = '()',
        public string $gyroscope = '()',
        public string $magnetometer = '()',
        public string $payment = '()',
        public string $usb = '()',
        public string $autoplay = '(self)',
        public string $fullscreen = '(self)',
        public string $pictureInPicture = '(self)',
        public array $additional = [],
    ) {}

    #[NoDiscard]
    public function toHeaderValue(): string
    {
        $features = array_filter([
            'camera' => $this->camera,
            'microphone' => $this->microphone,
            'geolocation' => $this->geolocation,
            'accelerometer' => $this->accelerometer,
            'gyroscope' => $this->gyroscope,
            'magnetometer' => $this->magnetometer,
            'payment' => $this->payment,
            'usb' => $this->usb,
            'autoplay' => $this->autoplay,
            'fullscreen' => $this->fullscreen,
            'picture-in-picture' => $this->pictureInPicture,
            ...$this->additional,
        ], static fn(string $value): bool => $value !== '');

        $parts = array_map(
            static fn(string $feature, string $allowlist): string => $feature . '=' . $allowlist,
            array_keys($features),
            array_values($features),
        );

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $additional */
        $additional = is_array($data['additional'] ?? null)
            ? array_filter($data['additional'], is_string(...))
            : [];

        return new self(
            camera: self::str($data, 'camera', '()'),
            microphone: self::str($data, 'microphone', '()'),
            geolocation: self::str($data, 'geolocation', '()'),
            accelerometer: self::str($data, 'accelerometer', '()'),
            gyroscope: self::str($data, 'gyroscope', '()'),
            magnetometer: self::str($data, 'magnetometer', '()'),
            payment: self::str($data, 'payment', '()'),
            usb: self::str($data, 'usb', '()'),
            autoplay: self::str($data, 'autoplay', '(self)'),
            fullscreen: self::str($data, 'fullscreen', '(self)'),
            pictureInPicture: self::str($data, 'picture_in_picture', '(self)'),
            additional: $additional,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
