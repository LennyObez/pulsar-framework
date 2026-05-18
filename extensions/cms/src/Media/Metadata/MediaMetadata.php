<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Metadata;

use Pulsar\Api\Api;

use function array_filter;
use function count;
use function floatval;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function round;

/**
 * Structured EXIF/metadata extracted from an uploaded image.
 *
 * Provides typed access to common photographic metadata fields
 * and supports serialization for JSON storage.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MediaMetadata
{
    /**
     * @param string|null $dateTaken Date/time the photo was taken (EXIF DateTimeOriginal)
     * @param float|null $gpsLatitude GPS latitude in decimal degrees
     * @param float|null $gpsLongitude GPS longitude in decimal degrees
     * @param string|null $cameraMake Camera manufacturer
     * @param string|null $cameraModel Camera model
     * @param string|null $lens Lens model
     * @param string|null $focalLength Focal length (e.g., "50mm")
     * @param string|null $aperture Aperture f-stop (e.g., "f/2.8")
     * @param string|null $exposureTime Exposure time (e.g., "1/250")
     * @param int|null $iso ISO sensitivity
     * @param string|null $flash Flash status description
     * @param string|null $whiteBalance White balance mode
     * @param int|null $orientation EXIF orientation value (1-8)
     * @param string|null $colorSpace Color space (e.g., "sRGB")
     * @param int|null $xResolution Horizontal resolution in DPI
     * @param int|null $yResolution Vertical resolution in DPI
     * @param string|null $software Software used to process the image
     * @param string|null $copyright Copyright notice from EXIF
     * @param string|null $description Image description from EXIF
     */
    public function __construct(
        public ?string $dateTaken = null,
        public ?float $gpsLatitude = null,
        public ?float $gpsLongitude = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
        public ?string $lens = null,
        public ?string $focalLength = null,
        public ?string $aperture = null,
        public ?string $exposureTime = null,
        public ?int $iso = null,
        public ?string $flash = null,
        public ?string $whiteBalance = null,
        public ?int $orientation = null,
        public ?string $colorSpace = null,
        public ?int $xResolution = null,
        public ?int $yResolution = null,
        public ?string $software = null,
        public ?string $copyright = null,
        public ?string $description = null,
    ) {}

    /**
     * Build a MediaMetadata instance from raw EXIF data.
     *
     * @param array<string, mixed> $exifData Raw EXIF data as returned by exif_read_data()
     */
    public static function fromExif(array $exifData): self
    {
        /** @var array<string, mixed> $exifSection */
        $exifSection = is_array($exifData['EXIF'] ?? null) ? $exifData['EXIF'] : [];

        return new self(
            dateTaken: self::extractString($exifData, ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime']),
            gpsLatitude: self::extractGpsCoordinate($exifData, 'GPSLatitude', 'GPSLatitudeRef'),
            gpsLongitude: self::extractGpsCoordinate($exifData, 'GPSLongitude', 'GPSLongitudeRef'),
            cameraMake: self::extractString($exifData, ['Make']),
            cameraModel: self::extractString($exifData, ['Model']),
            lens: self::extractString($exifData, ['UndefinedTag:0xA434', 'LensModel', 'LensInfo']),
            focalLength: self::formatRational($exifData['FocalLength'] ?? $exifSection['FocalLength'] ?? null, 'mm'),
            aperture: self::formatFNumber($exifData['FNumber'] ?? $exifSection['FNumber'] ?? null),
            exposureTime: self::formatExposureTime($exifData['ExposureTime'] ?? $exifSection['ExposureTime'] ?? null),
            iso: self::extractIso($exifData),
            flash: self::describeFlash($exifData['Flash'] ?? $exifSection['Flash'] ?? null),
            whiteBalance: self::describeWhiteBalance($exifData['WhiteBalance'] ?? $exifSection['WhiteBalance'] ?? null),
            orientation: self::extractInt($exifData, ['Orientation']),
            colorSpace: self::describeColorSpace($exifData['ColorSpace'] ?? $exifSection['ColorSpace'] ?? null),
            xResolution: self::rationalToInt($exifData['XResolution'] ?? null),
            yResolution: self::rationalToInt($exifData['YResolution'] ?? null),
            software: self::extractString($exifData, ['Software']),
            copyright: self::extractString($exifData, ['Copyright']),
            description: self::extractString($exifData, ['ImageDescription']),
        );
    }

    /**
     * Return GPS coordinates as [latitude, longitude], or null if unavailable.
     *
     * @return array{0: float, 1: float}|null
     */
    public function getGpsCoordinates(): ?array
    {
        if ($this->gpsLatitude === null || $this->gpsLongitude === null) {
            return null;
        }

        return [$this->gpsLatitude, $this->gpsLongitude];
    }

    /**
     * Whether this metadata contains any GPS data.
     */
    public function hasGps(): bool
    {
        return $this->gpsLatitude !== null && $this->gpsLongitude !== null;
    }

    /**
     * Serialize to array for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'date_taken' => $this->dateTaken,
            'gps_latitude' => $this->gpsLatitude,
            'gps_longitude' => $this->gpsLongitude,
            'camera_make' => $this->cameraMake,
            'camera_model' => $this->cameraModel,
            'lens' => $this->lens,
            'focal_length' => $this->focalLength,
            'aperture' => $this->aperture,
            'exposure_time' => $this->exposureTime,
            'iso' => $this->iso,
            'flash' => $this->flash,
            'white_balance' => $this->whiteBalance,
            'orientation' => $this->orientation,
            'color_space' => $this->colorSpace,
            'x_resolution' => $this->xResolution,
            'y_resolution' => $this->yResolution,
            'software' => $this->software,
            'copyright' => $this->copyright,
            'description' => $this->description,
        ], static fn(mixed $v): bool => $v !== null);
    }

    /**
     * Reconstruct from a previously serialized array.
     *
     * @param array{
     *     date_taken?: string|null,
     *     gps_latitude?: float|int|null,
     *     gps_longitude?: float|int|null,
     *     camera_make?: string|null,
     *     camera_model?: string|null,
     *     lens?: string|null,
     *     focal_length?: string|null,
     *     aperture?: string|null,
     *     exposure_time?: string|null,
     *     iso?: int|null,
     *     flash?: string|null,
     *     white_balance?: string|null,
     *     orientation?: int|null,
     *     color_space?: string|null,
     *     x_resolution?: int|null,
     *     y_resolution?: int|null,
     *     software?: string|null,
     *     copyright?: string|null,
     *     description?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dateTaken: $data['date_taken'] ?? null,
            gpsLatitude: self::toNullableFloat($data['gps_latitude'] ?? null),
            gpsLongitude: self::toNullableFloat($data['gps_longitude'] ?? null),
            cameraMake: $data['camera_make'] ?? null,
            cameraModel: $data['camera_model'] ?? null,
            lens: $data['lens'] ?? null,
            focalLength: $data['focal_length'] ?? null,
            aperture: $data['aperture'] ?? null,
            exposureTime: $data['exposure_time'] ?? null,
            iso: $data['iso'] ?? null,
            flash: $data['flash'] ?? null,
            whiteBalance: $data['white_balance'] ?? null,
            orientation: $data['orientation'] ?? null,
            colorSpace: $data['color_space'] ?? null,
            xResolution: $data['x_resolution'] ?? null,
            yResolution: $data['y_resolution'] ?? null,
            software: $data['software'] ?? null,
            copyright: $data['copyright'] ?? null,
            description: $data['description'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function extractString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            /** @var mixed $value */
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            // Check inside EXIF/IFD0 sections
            foreach (['EXIF', 'IFD0', 'GPS', 'COMPUTED'] as $section) {
                /** @var mixed $sectionData */
                $sectionData = $data[$section] ?? null;
                if (!is_array($sectionData)) {
                    continue;
                }
                /** @var mixed $sectionValue */
                $sectionValue = $sectionData[$key] ?? null;
                if (is_string($sectionValue) && $sectionValue !== '') {
                    return $sectionValue;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function extractInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            /** @var mixed $value */
            $value = $data[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }

            foreach (['EXIF', 'IFD0'] as $section) {
                /** @var mixed $sectionData */
                $sectionData = $data[$section] ?? null;
                if (!is_array($sectionData)) {
                    continue;
                }
                /** @var mixed $sectionValue */
                $sectionValue = $sectionData[$key] ?? null;
                if (is_int($sectionValue)) {
                    return $sectionValue;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $exifData
     */
    private static function extractIso(array $exifData): ?int
    {
        /** @var mixed $exifSection */
        $exifSection = $exifData['EXIF'] ?? null;
        if (!is_array($exifSection)) {
            $exifSection = [];
        }
        /** @var mixed $iso */
        $iso = $exifData['ISOSpeedRatings'] ?? $exifSection['ISOSpeedRatings'] ?? null;

        if (is_int($iso)) {
            return $iso;
        }

        if (is_string($iso) && $iso !== '') {
            return (int) $iso;
        }

        return null;
    }

    /**
     * Convert EXIF GPS coordinate arrays to decimal degrees.
     *
     * @param array<string, mixed> $data
     */
    private static function extractGpsCoordinate(array $data, string $coordKey, string $refKey): ?float
    {
        /** @var mixed $gpsSection */
        $gpsSection = $data['GPS'] ?? null;
        if (!is_array($gpsSection)) {
            $gpsSection = $data;
        }

        /** @var mixed $coord */
        $coord = $gpsSection[$coordKey] ?? null;
        /** @var mixed $ref */
        $ref = $gpsSection[$refKey] ?? null;

        if (!is_array($coord) || !is_string($ref)) {
            return null;
        }

        if (count($coord) < 3) {
            return null;
        }

        /** @var mixed $rawDeg */
        $rawDeg = $coord[0] ?? '0';
        /** @var mixed $rawMin */
        $rawMin = $coord[1] ?? '0';
        /** @var mixed $rawSec */
        $rawSec = $coord[2] ?? '0';
        $degrees = self::rationalToFloat(is_string($rawDeg) ? $rawDeg : '0');
        $minutes = self::rationalToFloat(is_string($rawMin) ? $rawMin : '0');
        $seconds = self::rationalToFloat(is_string($rawSec) ? $rawSec : '0');

        $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);

        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }

        return round($decimal, 6);
    }

    /**
     * Convert an EXIF rational value (e.g., "50/1") to float.
     */
    private static function rationalToFloat(string $rational): float
    {
        $parts = explode('/', $rational);

        if (count($parts) !== 2) {
            return floatval($rational);
        }

        $numerator = floatval($parts[0]);
        $denominator = floatval($parts[1]);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    private static function rationalToInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            return (int) self::rationalToFloat($value);
        }

        return null;
    }

    private static function formatRational(mixed $value, string $suffix): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $floatVal = self::rationalToFloat($value);

            return round($floatVal, 1) . $suffix;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 1) . $suffix;
        }

        return null;
    }

    private static function formatFNumber(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $floatVal = self::rationalToFloat($value);

            return 'f/' . round($floatVal, 1);
        }

        if (is_int($value) || is_float($value)) {
            return 'f/' . round((float) $value, 1);
        }

        return null;
    }

    private static function formatExposureTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            // Already in rational form like "1/250"
            if (str_contains($value, '/')) {
                return $value;
            }

            $floatVal = floatval($value);

            if ($floatVal >= 1.0) {
                return $value . 's';
            }

            if ($floatVal > 0.0) {
                return '1/' . (int) round(1.0 / $floatVal);
            }
        }

        return null;
    }

    private static function describeFlash(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (!is_int($value)) {
            return null;
        }

        return match ($value) {
            0x00 => 'No flash',
            0x01 => 'Flash fired',
            0x05 => 'Flash fired, strobe return not detected',
            0x07 => 'Flash fired, strobe return detected',
            0x08 => 'No flash, compulsory mode',
            0x09 => 'Flash fired, compulsory mode',
            0x0D => 'Flash fired, compulsory mode, return not detected',
            0x0F => 'Flash fired, compulsory mode, return detected',
            0x10 => 'No flash, compulsory suppression',
            0x18 => 'No flash, auto mode',
            0x19 => 'Flash fired, auto mode',
            0x1D => 'Flash fired, auto mode, return not detected',
            0x1F => 'Flash fired, auto mode, return detected',
            0x20 => 'No flash function',
            0x41 => 'Flash fired, red-eye reduction',
            0x49 => 'Flash fired, red-eye reduction, compulsory mode',
            0x4D => 'Flash fired, red-eye reduction, compulsory, return not detected',
            0x4F => 'Flash fired, red-eye reduction, compulsory, return detected',
            0x59 => 'Flash fired, red-eye reduction, auto mode',
            0x5D => 'Flash fired, red-eye reduction, auto, return not detected',
            0x5F => 'Flash fired, red-eye reduction, auto, return detected',
            default => ($value & 0x01) !== 0 ? 'Flash fired' : 'No flash',
        };
    }

    private static function describeWhiteBalance(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_int($value)) {
            return null;
        }

        return match ($value) {
            0 => 'Auto',
            1 => 'Manual',
            default => 'Unknown',
        };
    }

    private static function describeColorSpace(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_int($value)) {
            return null;
        }

        return match ($value) {
            1 => 'sRGB',
            2 => 'Adobe RGB',
            0xFFFF => 'Uncalibrated',
            default => 'Unknown',
        };
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }
}
