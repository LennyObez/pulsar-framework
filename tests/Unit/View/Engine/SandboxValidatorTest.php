<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\SandboxValidator;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

#[CoversClass(SandboxValidator::class)]
final class SandboxValidatorTest extends TestCase
{
    private SandboxValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SandboxValidator();
    }

    // --- Allowlist acceptance ---

    #[Test]
    #[DataProvider('allowedFunctionProvider')]
    public function validateAcceptsAllowlistedFunctions(string $function): void
    {
        $code = "<?php echo {$function}(\$value); ?>";

        $this->validator->validate($code, 'test.pulse.php');

        self::assertTrue($this->validator->isFunctionAllowed($function));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedFunctionProvider(): iterable
    {
        yield 'htmlspecialchars' => ['htmlspecialchars'];
        yield 'strtolower' => ['strtolower'];
        yield 'trim' => ['trim'];
        yield 'count' => ['count'];
        yield 'number_format' => ['number_format'];
        yield 'json_encode' => ['json_encode'];
        yield 'sprintf' => ['sprintf'];
        yield 'strlen' => ['strlen'];
        yield 'array_key_exists' => ['array_key_exists'];
        yield 'in_array' => ['in_array'];
        yield 'date' => ['date'];
        yield 'urlencode' => ['urlencode'];
        yield 'mb_strtolower' => ['mb_strtolower'];
        yield 'implode' => ['implode'];
        yield 'str_contains' => ['str_contains'];
    }

    #[Test]
    public function validateAcceptsCodeUsingOnlyAllowlistedSymbols(): void
    {
        $code = '<?php echo htmlspecialchars((string) ($name), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>';

        $this->validator->validate($code, 'safe.pulse.php');

        self::assertTrue($this->validator->isFunctionAllowed('htmlspecialchars'));
    }

    #[Test]
    public function validateAcceptsControlFlowStructures(): void
    {
        $code = <<<'PHP'
            <?php if ($condition): ?>
                <p>Content</p>
            <?php elseif ($other): ?>
                <p>Other</p>
            <?php else: ?>
                <p>Default</p>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <li><?php echo htmlspecialchars($item); ?></li>
            <?php endforeach; ?>
            PHP;

        $this->validator->validate($code, 'flow.pulse.php');

        // All control flow tokens are infrastructure and should be allowed
        self::assertTrue($this->validator->isFunctionAllowed('if'));
        self::assertTrue($this->validator->isFunctionAllowed('foreach'));
    }

    // --- Disallowed function rejection ---

    #[Test]
    #[DataProvider('disallowedFunctionProvider')]
    public function validateRejectsNonAllowlistedFunctions(string $function): void
    {
        $code = "<?php {$function}('arg'); ?>";

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/Sandbox violation.*function/');

        $this->validator->validate($code, 'test.pulse.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedFunctionProvider(): iterable
    {
        yield 'shell_exec' => ['shell_exec'];
        yield 'file_get_contents' => ['file_get_contents'];
        yield 'file_put_contents' => ['file_put_contents'];
        yield 'eval' => ['eval'];
        yield 'phpinfo' => ['phpinfo'];
        yield 'unserialize' => ['unserialize'];
        yield 'mail' => ['mail'];
        yield 'ini_set' => ['ini_set'];
        yield 'curl_init' => ['curl_init'];
        yield 'popen' => ['popen'];
        yield 'proc_open' => ['proc_open'];
    }

    // --- Disallowed class rejection ---

    #[Test]
    #[DataProvider('disallowedClassProvider')]
    public function validateRejectsNonAllowlistedClasses(string $class): void
    {
        $code = "<?php \$x = new {$class}(); ?>";

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/Sandbox violation.*class/');

        $this->validator->validate($code, 'test.pulse.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedClassProvider(): iterable
    {
        yield 'PDO' => ['PDO'];
        yield 'SQLite3' => ['SQLite3'];
        yield 'ReflectionClass' => ['ReflectionClass'];
        yield 'SplFileObject' => ['SplFileObject'];
        yield 'Fiber' => ['Fiber'];
        yield 'CurlHandle' => ['CurlHandle'];
    }

    #[Test]
    public function validateRejectsStaticMethodOnDisallowedClass(): void
    {
        $code = '<?php PDO::getAvailableDrivers(); ?>';

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/Sandbox violation/');

        $this->validator->validate($code, 'test.pulse.php');
    }

    // --- Allowed classes ---

    #[Test]
    #[DataProvider('allowedClassProvider')]
    public function validateAcceptsAllowlistedClasses(string $class): void
    {
        $code = "<?php \$x = new {$class}(); ?>";

        $this->validator->validate($code, 'test.pulse.php');

        self::assertTrue($this->validator->isClassAllowed($class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedClassProvider(): iterable
    {
        yield 'DateTimeImmutable' => ['DateTimeImmutable'];
        yield 'stdClass' => ['stdClass'];
        yield 'ArrayObject' => ['ArrayObject'];
    }

    // --- Customization ---

    #[Test]
    public function additionalFunctionsExpandAllowlist(): void
    {
        $validator = new SandboxValidator(additionalFunctions: ['custom_helper']);

        $code = '<?php echo custom_helper($x); ?>';

        $validator->validate($code, 'test.pulse.php');

        self::assertTrue($validator->isFunctionAllowed('custom_helper'));
    }

    #[Test]
    public function additionalClassesExpandAllowlist(): void
    {
        $validator = new SandboxValidator(additionalClasses: ['MyViewModel']);

        $code = '<?php $vm = new MyViewModel(); ?>';

        $validator->validate($code, 'test.pulse.php');

        self::assertTrue($validator->isClassAllowed('MyViewModel'));
    }

    #[Test]
    public function removedFunctionsShrinkAllowlist(): void
    {
        $validator = new SandboxValidator(removedFunctions: ['json_encode']);

        $code = '<?php echo json_encode($data); ?>';

        $this->expectException(ViewException::class);

        $validator->validate($code, 'test.pulse.php');
    }

    #[Test]
    public function removedClassesShrinkAllowlist(): void
    {
        $validator = new SandboxValidator(removedClasses: ['DateTimeImmutable']);

        $code = '<?php $dt = new DateTimeImmutable(); ?>';

        $this->expectException(ViewException::class);

        $validator->validate($code, 'test.pulse.php');
    }

    // --- fromConfig factory ---

    #[Test]
    public function fromConfigReturnsValidatorWhenSandboxEnabled(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
            sandboxMode: true,
        );

        $validator = SandboxValidator::fromConfig($config);

        self::assertInstanceOf(SandboxValidator::class, $validator);
    }

    #[Test]
    public function fromConfigReturnsNullWhenSandboxDisabled(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
            sandboxMode: false,
        );

        self::assertNull(SandboxValidator::fromConfig($config));
    }

    // --- Introspection ---

    #[Test]
    public function allowedFunctionsReturnsNonEmptyList(): void
    {
        $functions = $this->validator->allowedFunctions();

        self::assertNotEmpty($functions);
        self::assertContains('htmlspecialchars', $functions);
        self::assertContains('trim', $functions);
        self::assertContains('count', $functions);
    }

    #[Test]
    public function allowedClassesReturnsNonEmptyList(): void
    {
        $classes = $this->validator->allowedClasses();

        self::assertNotEmpty($classes);
        self::assertContains('DateTimeImmutable', $classes);
        self::assertContains('stdClass', $classes);
    }

    #[Test]
    public function isFunctionAllowedReturnsFalseForUnknownFunction(): void
    {
        self::assertFalse($this->validator->isFunctionAllowed('totally_unknown_func'));
    }

    #[Test]
    public function isClassAllowedReturnsFalseForUnknownClass(): void
    {
        self::assertFalse($this->validator->isClassAllowed('UnknownDangerousClass'));
    }

    // --- Edge cases ---

    #[Test]
    public function validateAcceptsEmptyCode(): void
    {
        $this->validator->validate('', 'empty.pulse.php');

        self::assertNotEmpty($this->validator->allowedFunctions());
    }

    #[Test]
    public function validateAcceptsPlainHtmlWithoutPhp(): void
    {
        $this->validator->validate('<div><p>Hello, world!</p></div>', 'html.pulse.php');

        self::assertNotEmpty($this->validator->allowedFunctions());
    }

    #[Test]
    public function exceptionContainsTemplateNameAndSymbol(): void
    {
        try {
            $this->validator->validate('<?php shell_exec("ls"); ?>', 'my-template.pulse.php');
            self::fail('Expected ViewException');
        } catch (ViewException $e) {
            self::assertStringContainsString('my-template.pulse.php', $e->getMessage());
            self::assertStringContainsString('shell_exec', $e->getMessage());
        }
    }

    #[Test]
    public function validateHandlesMultipleFunctionCallsWithMixedAllowance(): void
    {
        // htmlspecialchars is allowed, shell_exec is not
        $code = '<?php echo htmlspecialchars($x); shell_exec("bad"); ?>';

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/shell_exec/');

        $this->validator->validate($code, 'mixed.pulse.php');
    }

    #[Test]
    public function validateHandlesMultipleClassReferencesWithMixedAllowance(): void
    {
        // DateTimeImmutable is allowed, PDO is not
        $code = '<?php $dt = new DateTimeImmutable(); $db = new PDO("sqlite::memory:"); ?>';

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/PDO/');

        $this->validator->validate($code, 'mixed.pulse.php');
    }
}
