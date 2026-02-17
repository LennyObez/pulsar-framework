<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Metadata\MediaMetadata;

#[CoversClass(MediaMetadata::class)]
final class MediaMetadataTest extends TestCase
{
    #[Test]
    public function fromExif_extracts_camera_make_and_model(): void
    {
        $exif = ['Make' => 'Canon', 'Model' => 'EOS R5'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Canon', $metadata->cameraMake);
        self::assertSame('EOS R5', $metadata->cameraModel);
    }

    #[Test]
    public function fromExif_extracts_date_time_original(): void
    {
        $exif = ['DateTimeOriginal' => '2025:12:25 14:30:00'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('2025:12:25 14:30:00', $metadata->dateTaken);
    }

    #[Test]
    public function fromExif_falls_back_to_datetime_digitized(): void
    {
        $exif = ['DateTimeDigitized' => '2025:11:01 10:00:00'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('2025:11:01 10:00:00', $metadata->dateTaken);
    }

    #[Test]
    public function fromExif_extracts_iso_speed(): void
    {
        $exif = ['ISOSpeedRatings' => 800];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame(800, $metadata->iso);
    }

    #[Test]
    public function fromExif_extracts_string_iso(): void
    {
        $exif = ['ISOSpeedRatings' => '3200'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame(3200, $metadata->iso);
    }

    #[Test]
    public function fromExif_extracts_focal_length_from_rational(): void
    {
        $exif = ['FocalLength' => '50/1'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('50mm', $metadata->focalLength);
    }

    #[Test]
    public function fromExif_extracts_aperture_from_rational(): void
    {
        $exif = ['FNumber' => '28/10'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('f/2.8', $metadata->aperture);
    }

    #[Test]
    public function fromExif_extracts_exposure_time_rational(): void
    {
        $exif = ['ExposureTime' => '1/250'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('1/250', $metadata->exposureTime);
    }

    #[Test]
    public function fromExif_extracts_gps_coordinates_north_east(): void
    {
        $exif = [
            'GPS' => [
                'GPSLatitude' => ['48/1', '51/1', '24/1'],
                'GPSLatitudeRef' => 'N',
                'GPSLongitude' => ['2/1', '21/1', '7/1'],
                'GPSLongitudeRef' => 'E',
            ],
        ];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertNotNull($metadata->gpsLatitude);
        self::assertNotNull($metadata->gpsLongitude);
        self::assertEqualsWithDelta(48.856667, $metadata->gpsLatitude, 0.001);
        self::assertEqualsWithDelta(2.351944, $metadata->gpsLongitude, 0.001);
    }

    #[Test]
    public function fromExif_negates_gps_for_south_and_west(): void
    {
        $exif = [
            'GPS' => [
                'GPSLatitude' => ['33/1', '52/1', '10/1'],
                'GPSLatitudeRef' => 'S',
                'GPSLongitude' => ['151/1', '12/1', '30/1'],
                'GPSLongitudeRef' => 'W',
            ],
        ];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertNotNull($metadata->gpsLatitude);
        self::assertLessThan(0, $metadata->gpsLatitude);
        self::assertNotNull($metadata->gpsLongitude);
        self::assertLessThan(0, $metadata->gpsLongitude);
    }

    #[Test]
    public function getGpsCoordinates_returns_null_when_no_gps(): void
    {
        $metadata = new MediaMetadata();

        self::assertNull($metadata->getGpsCoordinates());
        self::assertFalse($metadata->hasGps());
    }

    #[Test]
    public function getGpsCoordinates_returns_tuple_when_present(): void
    {
        $metadata = new MediaMetadata(gpsLatitude: 48.8566, gpsLongitude: 2.3522);

        $coords = $metadata->getGpsCoordinates();

        self::assertNotNull($coords);
        self::assertCount(2, $coords);
        self::assertSame(48.8566, $coords[0]);
        self::assertSame(2.3522, $coords[1]);
        self::assertTrue($metadata->hasGps());
    }

    #[Test]
    public function fromExif_describes_flash_fired(): void
    {
        $exif = ['Flash' => 0x01];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Flash fired', $metadata->flash);
    }

    #[Test]
    public function fromExif_describes_no_flash(): void
    {
        $exif = ['Flash' => 0x00];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('No flash', $metadata->flash);
    }

    #[Test]
    public function fromExif_describes_auto_white_balance(): void
    {
        $exif = ['WhiteBalance' => 0];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Auto', $metadata->whiteBalance);
    }

    #[Test]
    public function fromExif_describes_manual_white_balance(): void
    {
        $exif = ['WhiteBalance' => 1];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Manual', $metadata->whiteBalance);
    }

    #[Test]
    public function fromExif_describes_srgb_color_space(): void
    {
        $exif = ['ColorSpace' => 1];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('sRGB', $metadata->colorSpace);
    }

    #[Test]
    public function fromExif_extracts_orientation(): void
    {
        $exif = ['Orientation' => 6];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame(6, $metadata->orientation);
    }

    #[Test]
    public function fromExif_extracts_resolution(): void
    {
        $exif = ['XResolution' => '300/1', 'YResolution' => '300/1'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame(300, $metadata->xResolution);
        self::assertSame(300, $metadata->yResolution);
    }

    #[Test]
    public function fromExif_extracts_software(): void
    {
        $exif = ['Software' => 'Adobe Lightroom Classic 13.0'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Adobe Lightroom Classic 13.0', $metadata->software);
    }

    #[Test]
    public function fromExif_extracts_copyright(): void
    {
        $exif = ['Copyright' => '2025 John Doe Photography'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('2025 John Doe Photography', $metadata->copyright);
    }

    #[Test]
    public function fromExif_extracts_description(): void
    {
        $exif = ['ImageDescription' => 'Sunset over the mountains'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Sunset over the mountains', $metadata->description);
    }

    #[Test]
    public function fromExif_handles_nested_exif_sections(): void
    {
        $exif = [
            'EXIF' => [
                'FocalLength' => '85/1',
                'FNumber' => '14/10',
                'ISOSpeedRatings' => 100,
            ],
            'IFD0' => [
                'Make' => 'Nikon',
                'Model' => 'Z9',
            ],
        ];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('Nikon', $metadata->cameraMake);
        self::assertSame('Z9', $metadata->cameraModel);
        self::assertSame('85mm', $metadata->focalLength);
        self::assertSame('f/1.4', $metadata->aperture);
        self::assertSame(100, $metadata->iso);
    }

    #[Test]
    public function fromExif_returns_empty_for_no_data(): void
    {
        $metadata = MediaMetadata::fromExif([]);

        self::assertNull($metadata->dateTaken);
        self::assertNull($metadata->cameraMake);
        self::assertNull($metadata->cameraModel);
        self::assertNull($metadata->iso);
        self::assertNull($metadata->gpsLatitude);
        self::assertNull($metadata->gpsLongitude);
    }

    #[Test]
    public function toArray_excludes_null_fields(): void
    {
        $metadata = new MediaMetadata(cameraMake: 'Canon', iso: 400);

        $array = $metadata->toArray();

        self::assertSame('Canon', $array['camera_make']);
        self::assertSame(400, $array['iso']);
        self::assertArrayNotHasKey('date_taken', $array);
        self::assertArrayNotHasKey('gps_latitude', $array);
        self::assertArrayNotHasKey('lens', $array);
    }

    #[Test]
    public function toArray_includes_all_set_fields(): void
    {
        $metadata = new MediaMetadata(
            dateTaken: '2025:12:25 14:30:00',
            gpsLatitude: 48.8566,
            gpsLongitude: 2.3522,
            cameraMake: 'Canon',
            cameraModel: 'EOS R5',
            lens: 'RF 50mm F1.2L',
            focalLength: '50mm',
            aperture: 'f/1.2',
            exposureTime: '1/250',
            iso: 100,
            flash: 'No flash',
            whiteBalance: 'Auto',
            orientation: 1,
            colorSpace: 'sRGB',
            xResolution: 300,
            yResolution: 300,
            software: 'Lightroom',
            copyright: '2025 Photographer',
            description: 'A beautiful scene',
        );

        $array = $metadata->toArray();

        self::assertCount(19, $array);
        self::assertSame('2025:12:25 14:30:00', $array['date_taken']);
        self::assertSame(48.8566, $array['gps_latitude']);
        self::assertSame(2.3522, $array['gps_longitude']);
        self::assertSame('Canon', $array['camera_make']);
        self::assertSame('EOS R5', $array['camera_model']);
    }

    #[Test]
    public function fromArray_roundtrips_with_toArray(): void
    {
        $original = new MediaMetadata(
            dateTaken: '2025:06:15 09:00:00',
            cameraMake: 'Sony',
            cameraModel: 'A7R V',
            iso: 1600,
            focalLength: '70mm',
            aperture: 'f/2.8',
            orientation: 1,
            colorSpace: 'sRGB',
        );

        $array = $original->toArray();
        $restored = MediaMetadata::fromArray($array);

        self::assertSame($original->dateTaken, $restored->dateTaken);
        self::assertSame($original->cameraMake, $restored->cameraMake);
        self::assertSame($original->cameraModel, $restored->cameraModel);
        self::assertSame($original->iso, $restored->iso);
        self::assertSame($original->focalLength, $restored->focalLength);
        self::assertSame($original->aperture, $restored->aperture);
        self::assertSame($original->orientation, $restored->orientation);
        self::assertSame($original->colorSpace, $restored->colorSpace);
    }

    #[Test]
    public function fromArray_handles_empty_data(): void
    {
        $metadata = MediaMetadata::fromArray([]);

        self::assertNull($metadata->dateTaken);
        self::assertNull($metadata->cameraMake);
        self::assertNull($metadata->iso);
    }

    #[Test]
    public function fromArray_ignores_invalid_types(): void
    {
        $metadata = MediaMetadata::fromArray([
            'date_taken' => 12345,
            'iso' => 'not an int',
            'camera_make' => true,
        ]);

        self::assertNull($metadata->dateTaken);
        self::assertNull($metadata->iso);
        self::assertNull($metadata->cameraMake);
    }

    #[Test]
    #[DataProvider('flashDescriptionProvider')]
    public function fromExif_describes_flash_variants(int $value, string $expected): void
    {
        $metadata = MediaMetadata::fromExif(['Flash' => $value]);

        self::assertSame($expected, $metadata->flash);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function flashDescriptionProvider(): iterable
    {
        yield 'no flash' => [0x00, 'No flash'];
        yield 'fired' => [0x01, 'Flash fired'];
        yield 'fired compulsory' => [0x09, 'Flash fired, compulsory mode'];
        yield 'auto no flash' => [0x18, 'No flash, auto mode'];
        yield 'auto fired' => [0x19, 'Flash fired, auto mode'];
        yield 'no function' => [0x20, 'No flash function'];
        yield 'red-eye' => [0x41, 'Flash fired, red-eye reduction'];
    }

    #[Test]
    #[DataProvider('colorSpaceProvider')]
    public function fromExif_describes_color_space_variants(int $value, string $expected): void
    {
        $metadata = MediaMetadata::fromExif(['ColorSpace' => $value]);

        self::assertSame($expected, $metadata->colorSpace);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function colorSpaceProvider(): iterable
    {
        yield 'sRGB' => [1, 'sRGB'];
        yield 'Adobe RGB' => [2, 'Adobe RGB'];
        yield 'uncalibrated' => [0xFFFF, 'Uncalibrated'];
        yield 'unknown' => [99, 'Unknown'];
    }

    #[Test]
    public function fromExif_handles_gps_with_insufficient_coordinates(): void
    {
        $exif = [
            'GPS' => [
                'GPSLatitude' => ['48/1'],
                'GPSLatitudeRef' => 'N',
                'GPSLongitude' => ['2/1', '21/1'],
                'GPSLongitudeRef' => 'E',
            ],
        ];

        $metadata = MediaMetadata::fromExif($exif);

        // Lat has <3 parts, should be null
        self::assertNull($metadata->gpsLatitude);
        // Long has <3 parts, should be null
        self::assertNull($metadata->gpsLongitude);
    }

    #[Test]
    public function fromExif_handles_gps_with_non_array_coordinates(): void
    {
        $exif = [
            'GPS' => [
                'GPSLatitude' => 'not an array',
                'GPSLatitudeRef' => 'N',
            ],
        ];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertNull($metadata->gpsLatitude);
    }

    #[Test]
    public function fromExif_handles_string_flash_value(): void
    {
        $metadata = MediaMetadata::fromExif(['Flash' => 'Flash did not fire']);

        self::assertSame('Flash did not fire', $metadata->flash);
    }

    #[Test]
    public function fromExif_handles_string_white_balance(): void
    {
        $metadata = MediaMetadata::fromExif(['WhiteBalance' => 'Daylight']);

        self::assertSame('Daylight', $metadata->whiteBalance);
    }

    #[Test]
    public function fromExif_handles_lens_from_undefined_tag(): void
    {
        $exif = ['UndefinedTag:0xA434' => 'RF 24-70mm F2.8L IS USM'];

        $metadata = MediaMetadata::fromExif($exif);

        self::assertSame('RF 24-70mm F2.8L IS USM', $metadata->lens);
    }

    #[Test]
    public function fromArray_preserves_gps_floats(): void
    {
        $metadata = MediaMetadata::fromArray([
            'gps_latitude' => 48.8566,
            'gps_longitude' => 2.3522,
        ]);

        self::assertSame(48.8566, $metadata->gpsLatitude);
        self::assertSame(2.3522, $metadata->gpsLongitude);
    }

    #[Test]
    public function fromArray_converts_int_gps_to_float(): void
    {
        $metadata = MediaMetadata::fromArray([
            'gps_latitude' => 48,
            'gps_longitude' => 2,
        ]);

        self::assertSame(48.0, $metadata->gpsLatitude);
        self::assertSame(2.0, $metadata->gpsLongitude);
    }
}
