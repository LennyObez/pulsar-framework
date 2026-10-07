<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Security\ParamValidator;

use function assert;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Comprehensive tests for ParamValidator covering all validation methods
 * with boundary conditions, adversarial inputs, and injection attempts.
 */
#[CoversClass(ParamValidator::class)]
final class ParamValidatorComprehensiveTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'param_validator_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                if (is_dir($f)) {
                    rmdir($f);
                } else {
                    unlink($f);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // --- validateClientId ---

    /**
     * @return array<string, array{string}>
     */
    public static function validClientIdProvider(): array
    {
        return [
            'simple alpha' => ['myClient'],
            'with digits' => ['client123'],
            'with hyphens' => ['my-client'],
            'with underscores' => ['my_client'],
            'single char' => ['a'],
            'max length 64' => [str_repeat('x', 64)],
            'mixed case' => ['MyClient_01-test'],
            'all digits' => ['12345'],
            'all hyphens and underscores' => ['_-_-_'],
        ];
    }

    #[Test]
    #[DataProvider('validClientIdProvider')]
    public function validateClientIdAcceptsValidInputs(string $clientId): void
    {
        self::assertSame($clientId, ParamValidator::validateClientId($clientId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidClientIdProvider(): array
    {
        return [
            'empty string' => [''],
            'too long (65 chars)' => [str_repeat('a', 65)],
            'contains space' => ['my client'],
            'contains dot' => ['my.client'],
            'contains slash' => ['my/client'],
            'contains backslash' => ['my\\client'],
            'contains semicolon' => ['client;rm'],
            'contains pipe' => ['client|echo'],
            'contains dollar' => ['client$var'],
            'contains at sign' => ['client@domain'],
            'contains exclamation' => ['client!'],
            'contains colon' => ['client:name'],
            'shell injection' => ['$(whoami)'],
            'backtick injection' => ['`id`'],
            'newline injection' => ["client\ninjection"],
            'tab injection' => ["client\tinjection"],
            'null byte injection' => ["client\x00injection"],
            'unicode characters' => ["cl\xC3\xAEent"],
        ];
    }

    #[Test]
    #[DataProvider('invalidClientIdProvider')]
    public function validateClientIdRejectsInvalidInputs(string $clientId): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateClientId($clientId);
    }

    // --- validateFilter ---

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function validFilterProvider(): array
    {
        return [
            'null returns null' => [null, null],
            'simple string' => ['App', 'App'],
            'namespace with backslash' => ['App\\Models\\User', 'App\\Models\\User'],
            'with colon' => ['route:list', 'route:list'],
            'with dot' => ['config.app', 'config.app'],
            'with hyphen' => ['my-route', 'my-route'],
            'with underscore' => ['my_model', 'my_model'],
            'max length (256)' => [str_repeat('a', 256), str_repeat('a', 256)],
            'alphanumeric' => ['abc123XYZ', 'abc123XYZ'],
        ];
    }

    #[Test]
    #[DataProvider('validFilterProvider')]
    public function validateFilterAcceptsValidInputs(?string $filter, ?string $expected): void
    {
        self::assertSame($expected, ParamValidator::validateFilter($filter));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFilterProvider(): array
    {
        return [
            'too long (257 chars)' => [str_repeat('a', 257)],
            'contains space' => ['my filter'],
            'contains dollar' => ['$(whoami)'],
            'contains backtick' => ['`id`'],
            'contains pipe' => ['cmd|other'],
            'contains semicolon' => ['cmd;other'],
            'contains ampersand' => ['cmd&other'],
            'contains angle brackets' => ['<script>'],
            'contains parentheses' => ['fn()'],
            'contains exclamation' => ['not!valid'],
            'contains hash' => ['filter#tag'],
            'contains forward slash' => ['path/segment'],
            'contains equal sign' => ['key=value'],
            'contains at sign' => ['user@domain'],
            'contains curly braces' => ['{inject}'],
            'contains square brackets' => ['arr[0]'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFilterProvider')]
    public function validateFilterRejectsInvalidInputs(string $filter): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateFilter($filter);
    }

    // --- validatePath ---

    #[Test]
    public function validatePathReturnsNullForNullInput(): void
    {
        self::assertNull(ParamValidator::validatePath(null, $this->tmpDir));
    }

    #[Test]
    public function validatePathRejectsDirectoryTraversal(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);
        $this->expectExceptionMessageIsOrContains('traversal');

        ParamValidator::validatePath('../../etc/passwd', $this->tmpDir);
    }

    #[Test]
    public function validatePathRejectsHiddenTraversal(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validatePath('src/../../../etc/shadow', $this->tmpDir);
    }

    #[Test]
    public function validatePathRejectsMiddleTraversal(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validatePath('src/..', $this->tmpDir);
    }

    #[Test]
    public function validatePathReturnsAbsoluteForNonExistentFile(): void
    {
        $result = ParamValidator::validatePath('nonexistent.txt', $this->tmpDir);

        self::assertNotNull($result);
        self::assertStringContainsString('nonexistent.txt', $result);
        assert($this->tmpDir !== '');
        self::assertStringStartsWith($this->tmpDir, $result);
    }

    #[Test]
    public function validatePathResolvesExistingFile(): void
    {
        $file = $this->tmpDir . DIRECTORY_SEPARATOR . 'existing.txt';
        file_put_contents($file, 'test');

        $result = ParamValidator::validatePath('existing.txt', $this->tmpDir);

        self::assertNotNull($result);
        // On systems with realpath, the result should be an absolute path
        self::assertStringContainsString('existing.txt', $result);
    }

    // --- validateType ---

    #[Test]
    public function validateTypeAcceptsPhp(): void
    {
        self::assertSame('php', ParamValidator::validateType('php'));
    }

    #[Test]
    public function validateTypeAcceptsJs(): void
    {
        self::assertSame('js', ParamValidator::validateType('js'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTypeProvider(): array
    {
        return [
            'python' => ['python'],
            'typescript' => ['ts'],
            'ruby' => ['ruby'],
            'empty' => [''],
            'uppercase PHP' => ['PHP'],
            'uppercase JS' => ['JS'],
            'shell injection' => ['php;rm -rf /'],
            'mixed case' => ['Php'],
        ];
    }

    #[Test]
    #[DataProvider('invalidTypeProvider')]
    public function validateTypeRejectsInvalid(string $type): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateType($type);
    }

    // --- validateAnalyzer ---

    #[Test]
    public function validateAnalyzerAcceptsPhpstan(): void
    {
        self::assertSame('phpstan', ParamValidator::validateAnalyzer('phpstan'));
    }

    #[Test]
    public function validateAnalyzerAcceptsPsalm(): void
    {
        self::assertSame('psalm', ParamValidator::validateAnalyzer('psalm'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAnalyzerProvider(): array
    {
        return [
            'eslint' => ['eslint'],
            'phan' => ['phan'],
            'empty' => [''],
            'uppercase PHPSTAN' => ['PHPSTAN'],
            'uppercase PSALM' => ['PSALM'],
            'injection' => ['phpstan;cat /etc/passwd'],
            'mixed case' => ['PhpStan'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAnalyzerProvider')]
    public function validateAnalyzerRejectsInvalid(string $analyzer): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateAnalyzer($analyzer);
    }

    // --- Boundary conditions ---

    #[Test]
    public function validateClientIdAcceptsBoundary64Chars(): void
    {
        $id = str_repeat('A', 64);
        self::assertSame($id, ParamValidator::validateClientId($id));
    }

    #[Test]
    public function validateClientIdRejectsBoundary65Chars(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateClientId(str_repeat('A', 65));
    }

    #[Test]
    public function validateFilterAcceptsBoundary256Chars(): void
    {
        $filter = str_repeat('a', 256);
        self::assertSame($filter, ParamValidator::validateFilter($filter));
    }

    #[Test]
    public function validateFilterRejectsBoundary257Chars(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32602);

        ParamValidator::validateFilter(str_repeat('a', 257));
    }

    #[Test]
    public function validateFilterAcceptsSingleChar(): void
    {
        self::assertSame('x', ParamValidator::validateFilter('x'));
    }

    #[Test]
    public function validateClientIdAcceptsSingleChar(): void
    {
        self::assertSame('x', ParamValidator::validateClientId('x'));
    }

    // --- Exception messages ---

    #[Test]
    public function validateClientIdExceptionHasCorrectMessage(): void
    {
        try {
            ParamValidator::validateClientId('invalid client!');
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(-32602, $e->getCode());
            self::assertStringContainsString('client_id', $e->getMessage());
        }
    }

    #[Test]
    public function validateFilterExceedingLengthHasCorrectMessage(): void
    {
        try {
            ParamValidator::validateFilter(str_repeat('a', 300));
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(-32602, $e->getCode());
            self::assertStringContainsString('maximum length', $e->getMessage());
        }
    }

    #[Test]
    public function validateFilterInvalidCharsHasCorrectMessage(): void
    {
        try {
            ParamValidator::validateFilter('invalid$chars');
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(-32602, $e->getCode());
            self::assertStringContainsString('invalid characters', $e->getMessage());
        }
    }

    #[Test]
    public function validatePathTraversalHasCorrectMessage(): void
    {
        try {
            ParamValidator::validatePath('../test', $this->tmpDir);
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(-32602, $e->getCode());
            self::assertStringContainsString('traversal', $e->getMessage());
        }
    }

    #[Test]
    public function validateTypeHasCorrectMessage(): void
    {
        try {
            ParamValidator::validateType('python');
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(-32602, $e->getCode());
            self::assertStringContainsString('"php"', $e->getMessage());
            self::assertStringContainsString('"js"', $e->getMessage());
        }
    }

    #[Test]
    public function validateAnalyzerHasCorrectMessage(): void
    {
        try {
            ParamValidator::validateAnalyzer('eslint');
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(-32602, $e->getCode());
            self::assertStringContainsString('"phpstan"', $e->getMessage());
            self::assertStringContainsString('"psalm"', $e->getMessage());
        }
    }
}
