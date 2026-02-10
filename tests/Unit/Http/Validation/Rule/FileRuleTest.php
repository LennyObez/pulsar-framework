<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\FileRule;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

#[CoversClass(FileRule::class)]
final class FileRuleTest extends TestCase
{
    private FileRule $rule;

    protected function setUp(): void
    {
        $this->rule = new FileRule();
    }

    #[Test]
    public function validUploadPasses(): void
    {
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        self::assertNull($this->rule->validate('file', $file, []));
    }

    #[Test]
    public function uploadErrorFails(): void
    {
        $file = [
            'tmp_name' => '/tmp/phpABCDEF',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 1024,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];
        $violation = $this->rule->validate('file', $file, []);
        self::assertNotNull($violation);
        self::assertSame('file', $violation->rule);
    }

    #[Test]
    public function missingKeysFails(): void
    {
        $file = ['tmp_name' => '/tmp/phpABCDEF'];
        $violation = $this->rule->validate('file', $file, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $violation = $this->rule->validate('file', 'not-a-file', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('file', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new FileRule(message: 'Bad file.');
        $violation = $rule->validate('file', 'invalid', []);
        self::assertNotNull($violation);
        self::assertSame('Bad file.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('file', $this->rule->name());
    }
}
