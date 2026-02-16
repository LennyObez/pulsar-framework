<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\MaxFileSize;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

#[CoversClass(MaxFileSize::class)]
final class MaxFileSizeTest extends TestCase
{
    #[Test]
    public function fileSizeWithinLimitPasses(): void
    {
        $rule = new MaxFileSize(2048);
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        self::assertNull($rule->validate('file', $file, []));
    }

    #[Test]
    public function fileSizeAtLimitPasses(): void
    {
        $rule = new MaxFileSize(1024);
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        self::assertNull($rule->validate('file', $file, []));
    }

    #[Test]
    public function fileSizeExceedsLimitFails(): void
    {
        $rule = new MaxFileSize(1024);
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_OK,
            'size' => 2048,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        $violation = $rule->validate('file', $file, []);
        self::assertNotNull($violation);
        self::assertSame('max_file_size', $violation->rule);
    }

    #[Test]
    public function uploadErrorFails(): void
    {
        $rule = new MaxFileSize(2048);
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 1024,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        $violation = $rule->validate('file', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $rule = new MaxFileSize(1024);
        $violation = $rule->validate('file', 'string', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function missingKeysFails(): void
    {
        $rule = new MaxFileSize(1024);
        $violation = $rule->validate('file', ['size' => 100], []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new MaxFileSize(1024);
        self::assertNull($rule->validate('file', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new MaxFileSize(1024, message: 'Too big.');
        $file = [
            'tmp_name' => '/tmp/x',
            'error' => UPLOAD_ERR_OK,
            'size' => 9999,
            'name' => 'big.bin',
            'type' => 'application/octet-stream',
        ];
        $violation = $rule->validate('file', $file, []);
        self::assertNotNull($violation);
        self::assertSame('Too big.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('max_file_size', new MaxFileSize(1024)->name());
    }
}
