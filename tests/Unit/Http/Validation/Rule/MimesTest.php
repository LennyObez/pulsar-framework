<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Mimes;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

#[CoversClass(Mimes::class)]
final class MimesTest extends TestCase
{
    #[Test]
    public function allowedMimePasses(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'mime_test_');
        self::assertNotFalse($tmpFile);

        $img = imagecreatetruecolor(1, 1);
        self::assertNotFalse($img);
        imagejpeg($img, $tmpFile);

        $rule = new Mimes('image/jpeg', 'image/png');
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
        ];
        self::assertNull($rule->validate('file', $file, []));

        unlink($tmpFile);
    }

    #[Test]
    public function disallowedMimeFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'mime_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, '%PDF-1.4 fake pdf content');

        $rule = new Mimes('image/jpeg', 'image/png');
        $file = [
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'name' => 'doc.pdf',
            'type' => 'application/pdf',
        ];
        $violation = $rule->validate('file', $file, []);
        self::assertNotNull($violation);
        self::assertSame('mimes', $violation->rule);

        unlink($tmpFile);
    }

    #[Test]
    public function nonexistentFileFails(): void
    {
        $rule = new Mimes('image/jpeg');
        $file = [
            'tmp_name' => '/tmp/nonexistent_' . uniqid(),
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
        ];
        $violation = $rule->validate('file', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function uploadErrorFails(): void
    {
        $rule = new Mimes('image/jpeg');
        $file = [
            'tmp_name' => '/tmp/x',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 1024,
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
        ];
        $violation = $rule->validate('file', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function missingKeysFails(): void
    {
        $rule = new Mimes('image/jpeg');
        $violation = $rule->validate('file', ['name' => 'x'], []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $rule = new Mimes('image/jpeg');
        $violation = $rule->validate('file', 'string', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Mimes('image/jpeg');
        self::assertNull($rule->validate('file', null, []));
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('mimes', new Mimes('image/jpeg')->name());
    }
}
