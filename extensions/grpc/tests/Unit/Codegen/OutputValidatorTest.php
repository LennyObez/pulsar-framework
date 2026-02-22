<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Codegen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\OutputValidator;

#[CoversClass(OutputValidator::class)]
final class OutputValidatorTest extends TestCase
{
    private OutputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OutputValidator();
    }

    #[Test]
    public function validateReturnsEmptyForValidFiles(): void
    {
        $file = $this->createTempPhpFile('ValidClass', 'App\\Generated');

        try {
            $errors = $this->validator->validate([$file]);

            self::assertSame([], $errors);
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function validateDetectsMissingFiles(): void
    {
        $errors = $this->validator->validate(['/nonexistent/Missing.php']);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Generated file not found', $errors[0]);
    }

    #[Test]
    public function validateDetectsNamingConflicts(): void
    {
        $file1 = $this->createTempPhpFile('ConflictClass', 'App\\Generated', 'conflict1');
        $file2 = $this->createTempPhpFile('ConflictClass', 'App\\Generated', 'conflict2');

        try {
            $errors = $this->validator->validate([$file1, $file2]);

            self::assertNotEmpty($errors);
            self::assertStringContainsString('Class name conflict', $errors[0]);
            self::assertStringContainsString('App\\Generated\\ConflictClass', $errors[0]);
        } finally {
            @unlink($file1);
            @unlink($file2);
        }
    }

    #[Test]
    public function validatePassesForDifferentNamespaces(): void
    {
        $file1 = $this->createTempPhpFile('SameClass', 'App\\Generated\\V1', 'ns1');
        $file2 = $this->createTempPhpFile('SameClass', 'App\\Generated\\V2', 'ns2');

        try {
            $errors = $this->validator->validate([$file1, $file2]);

            self::assertSame([], $errors);
        } finally {
            @unlink($file1);
            @unlink($file2);
        }
    }

    #[Test]
    public function validateEmptyFileListReturnsEmpty(): void
    {
        $errors = $this->validator->validate([]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateDetectsSyntaxErrors(): void
    {
        $file = sys_get_temp_dir() . '/syntax_error_' . uniqid() . '.php';
        file_put_contents($file, '<?php class { broken syntax');

        try {
            $errors = $this->validator->validate([$file]);

            // PHP -l should catch the syntax error
            self::assertNotEmpty($errors);
            self::assertStringContainsString('Syntax error', $errors[0]);
        } finally {
            @unlink($file);
        }
    }

    private function createTempPhpFile(string $className, string $namespace, string $suffix = ''): string
    {
        $file = sys_get_temp_dir() . '/' . $className . '_' . ($suffix !== '' ? $suffix . '_' : '') . uniqid() . '.php';
        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            class {$className}
            {
            }
            PHP;

        file_put_contents($file, $content);

        return $file;
    }
}
