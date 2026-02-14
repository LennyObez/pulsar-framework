<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Image;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

#[CoversClass(Image::class)]
final class ImageTest extends TestCase
{
    private Image $rule;

    protected function setUp(): void
    {
        $this->rule = new Image();
    }

    #[Test]
    public function imageTypePasses(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(1, 1);
        self::assertNotFalse($img);
        imagepng($img, $tmpFile);

        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'photo.png',
            'type' => 'image/png',
        ];
        self::assertNull($this->rule->validate('photo', $file, []));

        unlink($tmpFile);
    }

    #[Test]
    public function nonImageTypeFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'not an image file');

        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'doc.pdf',
            'type' => 'application/pdf',
        ];
        $violation = $this->rule->validate('photo', $file, []);
        self::assertNotNull($violation);
        self::assertSame('image', $violation->rule);

        unlink($tmpFile);
    }

    #[Test]
    public function nonexistentFileFails(): void
    {
        $file = [
            'tmp_name' => '/tmp/nonexistent_' . uniqid(),
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
        ];
        $violation = $this->rule->validate('photo', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function uploadErrorFails(): void
    {
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 1024,
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
        ];
        $violation = $this->rule->validate('photo', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function missingKeysFails(): void
    {
        $violation = $this->rule->validate('photo', ['name' => 'photo.jpg'], []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $violation = $this->rule->validate('photo', 'not-a-file', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('photo', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'plain text');

        $rule = new Image(message: 'Must be image.');
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'a.txt',
            'type' => 'text/plain',
        ];
        $violation = $rule->validate('photo', $file, []);
        self::assertNotNull($violation);
        self::assertSame('Must be image.', $violation->message);

        unlink($tmpFile);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('image', $this->rule->name());
    }
}
