<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\OutputValidator;

#[CoversClass(OutputValidator::class)]
final class OutputValidatorTest extends TestCase
{
    private OutputValidator $validator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->validator = new OutputValidator();
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_output_validator_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function validateEmptyListReturnsNoErrors(): void
    {
        $errors = $this->validator->validate([]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateReportsNonExistentFiles(): void
    {
        $errors = $this->validator->validate(['/nonexistent/file.php']);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('not found', $errors[0]);
    }

    #[Test]
    public function validateAcceptsValidPhpFile(): void
    {
        $file = $this->tmpDir . '/ValidClass.php';
        file_put_contents($file, "<?php\n\nnamespace App\\Generated;\n\nclass ValidClass {}\n");

        $errors = $this->validator->validate([$file]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateDetectsSyntaxErrors(): void
    {
        $file = $this->tmpDir . '/BadSyntax.php';
        file_put_contents($file, "<?php\n\nclass BadSyntax {\n    public function broken( {\n    }\n}\n");

        $errors = $this->validator->validate([$file]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Syntax error', $errors[0]);
    }

    #[Test]
    public function validateDetectsNamingConflicts(): void
    {
        $file1 = $this->tmpDir . '/A.php';
        $file2 = $this->tmpDir . '/B.php';
        file_put_contents($file1, "<?php\n\nnamespace App\\Gen;\n\nclass Duplicate {}\n");
        file_put_contents($file2, "<?php\n\nnamespace App\\Gen;\n\nclass Duplicate {}\n");

        $errors = $this->validator->validate([$file1, $file2]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('conflict', $errors[0]);
    }

    #[Test]
    public function validateFileWithoutClass(): void
    {
        $file = $this->tmpDir . '/NoClass.php';
        file_put_contents($file, "<?php\n\necho 'hello';\n");

        $errors = $this->validator->validate([$file]);

        // No class means no conflict, and valid syntax
        self::assertSame([], $errors);
    }

    #[Test]
    public function validateFileWithoutNamespace(): void
    {
        $file = $this->tmpDir . '/GlobalClass.php';
        file_put_contents($file, "<?php\n\nclass GlobalClass {}\n");

        $errors = $this->validator->validate([$file]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateFileWithInterface(): void
    {
        $file = $this->tmpDir . '/MyInterface.php';
        file_put_contents($file, "<?php\n\nnamespace App;\n\ninterface MyInterface {}\n");

        $errors = $this->validator->validate([$file]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateFileWithEnum(): void
    {
        $file = $this->tmpDir . '/MyEnum.php';
        file_put_contents($file, "<?php\n\nnamespace App;\n\nenum MyEnum { case A; case B; }\n");

        $errors = $this->validator->validate([$file]);

        self::assertSame([], $errors);
    }
}
