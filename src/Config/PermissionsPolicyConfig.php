<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function implode;
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
final readonly class PermissionsPolicyConfig implements ReportsUnknownKeys
{
    /**
     * Keys read from the `headers.permissions_policy` sub-array of
     * config/security.php. Features beyond this list belong under `additional`,
     * which is an open map by design — that is what keeps this list closed.
     */
    private const array KNOWN_KEYS = [
        'camera', 'microphone', 'geolocation', 'accelerometer', 'gyroscope',
        'magnetometer', 'payment', 'usb', 'autoplay', 'fullscreen',
        'picture_in_picture', 'additional',
    ];

    /**
     * @param array<string, string> $additional Extra feature => allowlist pairs
     * @param list<string> $unknownKeys Keys present in the raw `permissions_policy`
     *     array that this DTO does not read — a browser feature written at the top
     *     level instead of under `additional` is silently dropped, leaving the
     *     feature at its default rather than the intended allowlist.
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
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
     * @param array{
     *     camera?: string,
     *     microphone?: string,
     *     geolocation?: string,
     *     accelerometer?: string,
     *     gyroscope?: string,
     *     magnetometer?: string,
     *     payment?: string,
     *     usb?: string,
     *     autoplay?: string,
     *     fullscreen?: string,
     *     picture_in_picture?: string,
     *     additional?: array<string, string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $additional = array_filter($data['additional'] ?? [], is_string(...));

        return new self(
            camera: $data['camera'] ?? '()',
            microphone: $data['microphone'] ?? '()',
            geolocation: $data['geolocation'] ?? '()',
            accelerometer: $data['accelerometer'] ?? '()',
            gyroscope: $data['gyroscope'] ?? '()',
            magnetometer: $data['magnetometer'] ?? '()',
            payment: $data['payment'] ?? '()',
            usb: $data['usb'] ?? '()',
            autoplay: $data['autoplay'] ?? '(self)',
            fullscreen: $data['fullscreen'] ?? '(self)',
            pictureInPicture: $data['picture_in_picture'] ?? '(self)',
            additional: $additional,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
