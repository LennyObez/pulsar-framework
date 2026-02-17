<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Attribute;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Attribute\CompiledValidationMap;
use stdClass;

final class CompiledValidationMapTest extends TestCase
{
    /** Fixed test artifact paths — cleaned up in tearDown. */
    private const string ARTIFACT_VALID = __DIR__ . '/fixtures/valid_artifact.php';
    private const string ARTIFACT_EMPTY = __DIR__ . '/fixtures/empty_artifact.php';
    private const string ARTIFACT_STRING = __DIR__ . '/fixtures/string_artifact.php';

    protected function setUp(): void
    {
        if (!is_dir(__DIR__ . '/fixtures')) {
            mkdir(__DIR__ . '/fixtures', 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up fixed artifact files
        foreach ([self::ARTIFACT_VALID, self::ARTIFACT_EMPTY, self::ARTIFACT_STRING] as $path) {
            if (file_exists($path)) {
                unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use — constant paths, not user input
            }
        }

        if (is_dir(__DIR__ . '/fixtures')) {
            @rmdir(__DIR__ . '/fixtures');
        }
    }

    #[Test]
    public function constructor_rejects_path_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('traversal');

        new CompiledValidationMap('../etc/passwd.php');
    }

    #[Test]
    public function constructor_rejects_non_php_extension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompiledValidationMap('/tmp/file.json');
    }

    #[Test]
    #[DataProvider('traversalPaths')]
    public function constructor_rejects_various_traversal_patterns(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompiledValidationMap($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalPaths(): iterable
    {
        yield 'parent dir' => ['../evil.php'];
        yield 'nested traversal' => ['foo/../../bar.php'];
        yield 'double dot mid-path' => ['/var/..hidden/test.php'];
    }

    #[Test]
    public function nonexistent_file_creates_empty_map(): void
    {
        $map = new CompiledValidationMap('/nonexistent/path/validations.php');

        self::assertFalse($map->has(stdClass::class));
        self::assertSame([], $map->rulesFor(stdClass::class));
        self::assertSame([], $map->filtersFor(stdClass::class));
    }

    #[Test]
    public function loads_valid_artifact_and_queries(): void
    {
        $className = stdClass::class;
        file_put_contents(self::ARTIFACT_VALID, "<?php return [
            '{$className}' => [
                'rules' => [
                    'email' => [
                        ['rule' => '{$className}', 'parameters' => [], 'groups' => ['default']],
                    ],
                ],
                'filters' => [
                    'name' => [
                        ['filter' => '{$className}', 'parameters' => []],
                    ],
                ],
            ],
        ];");

        $map = new CompiledValidationMap(self::ARTIFACT_VALID);

        self::assertTrue($map->has(stdClass::class));
        self::assertFalse($map->has(self::class));

        $rules = $map->rulesFor(stdClass::class);
        self::assertArrayHasKey('email', $rules);

        $filters = $map->filtersFor(stdClass::class);
        self::assertArrayHasKey('name', $filters);
    }

    #[Test]
    public function rules_for_returns_empty_for_unknown_class(): void
    {
        file_put_contents(self::ARTIFACT_EMPTY, '<?php return [];');

        $map = new CompiledValidationMap(self::ARTIFACT_EMPTY);

        self::assertSame([], $map->rulesFor(stdClass::class));
        self::assertSame([], $map->filtersFor(stdClass::class));
    }

    #[Test]
    public function handles_non_array_artifact_content(): void
    {
        file_put_contents(self::ARTIFACT_STRING, '<?php return "not an array";');

        $map = new CompiledValidationMap(self::ARTIFACT_STRING);

        self::assertFalse($map->has(stdClass::class));
    }
}
