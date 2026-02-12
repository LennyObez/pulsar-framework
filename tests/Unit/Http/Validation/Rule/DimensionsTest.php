<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Dimensions;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

#[CoversClass(Dimensions::class)]
final class DimensionsTest extends TestCase
{
    #[Test]
    public function validDimensionsWithRealImagePasses(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);

        // Create a 100x50 image
        $img = imagecreatetruecolor(100, 50);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $rule = new Dimensions(minWidth: 50, maxWidth: 200, minHeight: 25, maxHeight: 100);
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'test.png',
            'type' => 'image/png',
        ];
        self::assertNull($rule->validate('image', $file, []));

        unlink($tmpFile);
    }

    #[Test]
    public function widthTooSmallFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(10, 50);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $rule = new Dimensions(minWidth: 50);
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'test.png',
            'type' => 'image/png',
        ];
        $violation = $rule->validate('image', $file, []);
        self::assertNotNull($violation);
        self::assertSame('dimensions', $violation->rule);

        unlink($tmpFile);
    }

    #[Test]
    public function widthTooLargeFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(500, 50);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $rule = new Dimensions(maxWidth: 200);
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'test.png',
            'type' => 'image/png',
        ];
        $violation = $rule->validate('image', $file, []);
        self::assertNotNull($violation);

        unlink($tmpFile);
    }

    #[Test]
    public function nonImageFileFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'not an image');

        $rule = new Dimensions(minWidth: 10);
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        $violation = $rule->validate('image', $file, []);
        self::assertNotNull($violation);

        unlink($tmpFile);
    }

    #[Test]
    public function uploadErrorFails(): void
    {
        $rule = new Dimensions(minWidth: 10);
        $file = [
            'tmp_name' => '/tmp/x',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 1024,
            'name' => 'test.png',
            'type' => 'image/png',
        ];
        $violation = $rule->validate('image', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $rule = new Dimensions(minWidth: 10);
        $violation = $rule->validate('image', 'string', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Dimensions(minWidth: 10);
        self::assertNull($rule->validate('image', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Dimensions(minWidth: 9999, message: 'Bad dims.');
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(10, 10);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'test.png',
            'type' => 'image/png',
        ];
        $violation = $rule->validate('image', $file, []);
        self::assertNotNull($violation);
        self::assertSame('Bad dims.', $violation->message);

        unlink($tmpFile);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('dimensions', new Dimensions()->name());
    }
}
