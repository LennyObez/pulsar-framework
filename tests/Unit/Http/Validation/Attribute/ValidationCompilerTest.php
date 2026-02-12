<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Attribute;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Attribute\CompiledValidationMap;
use Pulsar\Http\Validation\Attribute\Sanitize;
use Pulsar\Http\Validation\Attribute\Validate;
use Pulsar\Http\Validation\Attribute\ValidationCompiler;
use Pulsar\Http\Validation\Filter\Trim;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\StringType;

use function file_exists;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(ValidationCompiler::class)]
#[CoversClass(CompiledValidationMap::class)]
final class ValidationCompilerTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        $this->outputPath = tempnam(sys_get_temp_dir(), 'validation_') . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
    }

    #[Test]
    public function compilesAttributesToArtifact(): void
    {
        $compiler = new ValidationCompiler();
        $compiler->compile([CompilerTestDto::class], $this->outputPath);

        self::assertFileExists($this->outputPath);

        $map = new CompiledValidationMap($this->outputPath);

        self::assertTrue($map->has(CompilerTestDto::class));

        $rules = $map->rulesFor(CompilerTestDto::class);
        self::assertArrayHasKey('name', $rules);
        self::assertCount(2, $rules['name']);
        self::assertSame(Required::class, $rules['name'][0]['rule']);
        self::assertSame(StringType::class, $rules['name'][1]['rule']);

        $filters = $map->filtersFor(CompilerTestDto::class);
        self::assertArrayHasKey('name', $filters);
        self::assertCount(1, $filters['name']);
        self::assertSame(Trim::class, $filters['name'][0]['filter']);
    }

    #[Test]
    public function compilesGroupsIntoArtifact(): void
    {
        $compiler = new ValidationCompiler();
        $compiler->compile([CompilerTestDto::class], $this->outputPath);

        $map = new CompiledValidationMap($this->outputPath);
        $rules = $map->rulesFor(CompilerTestDto::class);

        self::assertSame(['create'], $rules['name'][0]['groups']);
        self::assertSame([], $rules['name'][1]['groups']);
    }

    #[Test]
    public function mapReturnsFalseForUnknownClass(): void
    {
        $map = new CompiledValidationMap('/nonexistent/path.php');

        self::assertFalse($map->has('NonExistent\\Class'));
        self::assertSame([], $map->rulesFor('NonExistent\\Class'));
        self::assertSame([], $map->filtersFor('NonExistent\\Class'));
    }

    #[Test]
    public function compilesEmptyClassList(): void
    {
        $compiler = new ValidationCompiler();
        $compiler->compile([], $this->outputPath);

        $map = new CompiledValidationMap($this->outputPath);
        self::assertFalse($map->has(CompilerTestDto::class));
    }

    #[Test]
    public function skipsClassWithNoAttributes(): void
    {
        $compiler = new ValidationCompiler();
        $compiler->compile([EmptyDto::class], $this->outputPath);

        $map = new CompiledValidationMap($this->outputPath);
        self::assertFalse($map->has(EmptyDto::class));
    }

    #[Test]
    public function rejectsPathWithTraversalSequence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('traversal');

        new CompiledValidationMap('/some/../path/artifact.php');
    }

    #[Test]
    public function rejectsPathWithoutPhpExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('.php');

        $tmpFile = tempnam(sys_get_temp_dir(), 'val_') . '.txt';
        file_put_contents($tmpFile, '<?php return [];');

        try {
            new CompiledValidationMap($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }
}

/**
 * Test DTO with validation and sanitization attributes.
 */
final class CompilerTestDto
{
    #[Validate(rule: Required::class, groups: ['create'])]
    #[Validate(rule: StringType::class)]
    #[Sanitize(filter: Trim::class)]
    public string $name = '';

    #[Validate(rule: Required::class)]
    public string $email = '';
}

/**
 * Test DTO with no attributes.
 */
final class EmptyDto
{
    public string $value = '';
}
