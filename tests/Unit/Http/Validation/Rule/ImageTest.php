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
        $file = [
            'tmp_name' => '/tmp/nonexistent',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
        ];
        self::assertNull($this->rule->validate('photo', $file, []));
    }

    #[Test]
    public function nonImageTypeFails(): void
    {
        $file = [
            'tmp_name' => '/tmp/nonexistent',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'doc.pdf',
            'type' => 'application/pdf',
        ];
        $violation = $this->rule->validate('photo', $file, []);
        self::assertNotNull($violation);
        self::assertSame('image', $violation->rule);
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
        $rule = new Image(message: 'Must be image.');
        $file = [
            'tmp_name' => '/tmp/x',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
            'name' => 'a.txt',
            'type' => 'text/plain',
        ];
        $violation = $rule->validate('photo', $file, []);
        self::assertNotNull($violation);
        self::assertSame('Must be image.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('image', $this->rule->name());
    }
}
