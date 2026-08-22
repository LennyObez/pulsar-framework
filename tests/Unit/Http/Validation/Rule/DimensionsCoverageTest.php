<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Dimensions;

use function filesize;
use function imagecreatetruecolor;
use function imagepng;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const UPLOAD_ERR_OK;

#[CoversClass(Dimensions::class)]
final class DimensionsCoverageTest extends TestCase
{
    #[Test]
    public function heightTooSmallFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(100, 5);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $rule = new Dimensions(minHeight: 50);
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
    public function heightTooLargeFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dim_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(100, 500);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $rule = new Dimensions(maxHeight: 200);
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
    public function missingUploadKeysArrayFails(): void
    {
        $rule = new Dimensions(minWidth: 10);
        $violation = $rule->validate('image', ['not' => 'an upload'], []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tmpNameNotAFileFails(): void
    {
        $rule = new Dimensions(minWidth: 10);
        $file = [
            'tmp_name' => '/nonexistent/path/file.png',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'test.png',
            'type' => 'image/png',
        ];
        $violation = $rule->validate('image', $file, []);
        self::assertNotNull($violation);
    }
}
