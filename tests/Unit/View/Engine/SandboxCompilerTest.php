<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\SandboxCompiler;
use Pulsar\View\ViewException;

#[CoversClass(SandboxCompiler::class)]
final class SandboxCompilerTest extends TestCase
{
    private SandboxCompiler $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new SandboxCompiler();
    }

    #[Test]
    public function validateAcceptsSafeCode(): void
    {
        $code = '<?php echo htmlspecialchars($name, ENT_QUOTES, \'UTF-8\'); ?>';

        $this->sandbox->validate($code, 'test.pulse.php');

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('deniedFunctionProvider')]
    public function validateRejectsDeniedFunctions(string $function): void
    {
        $code = "<?php {$function}('arg'); ?>";

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/Sandbox violation.*function/');

        $this->sandbox->validate($code, 'test.pulse.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedFunctionProvider(): iterable
    {
        // Process execution functions — sandbox must block these
        yield 'shell_exec' => ['shell_exec'];
        yield 'system' => ['system'];
        yield 'passthru' => ['passthru'];
        yield 'file_get_contents' => ['file_get_contents'];
        yield 'file_put_contents' => ['file_put_contents'];
        yield 'eval' => ['eval'];
        yield 'phpinfo' => ['phpinfo'];
        yield 'unserialize' => ['unserialize'];
        yield 'mail' => ['mail'];
        yield 'ini_set' => ['ini_set'];
        yield 'putenv' => ['putenv'];
        yield 'curl_init' => ['curl_init'];
    }

    #[Test]
    #[DataProvider('deniedClassProvider')]
    public function validateRejectsDeniedClasses(string $class): void
    {
        $code = "<?php \$x = new {$class}(); ?>";

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/Sandbox violation.*class/');

        $this->sandbox->validate($code, 'test.pulse.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedClassProvider(): iterable
    {
        yield 'PDO' => ['PDO'];
        yield 'SQLite3' => ['SQLite3'];
        yield 'ReflectionClass' => ['ReflectionClass'];
        yield 'SplFileObject' => ['SplFileObject'];
    }

    #[Test]
    public function validateRejectsStaticMethodCallOnDeniedClass(): void
    {
        $code = '<?php PDO::getAvailableDrivers(); ?>';

        $this->expectException(ViewException::class);

        $this->sandbox->validate($code, 'test.pulse.php');
    }

    #[Test]
    public function validateAcceptsSafeFunctions(): void
    {
        $code = '<?php echo htmlspecialchars(strtoupper(trim($value))); ?>';

        $this->sandbox->validate($code, 'test.pulse.php');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function customAllowedFunctionsBypassesDenyList(): void
    {
        $sandbox = new SandboxCompiler(additionalAllowedFunctions: ['file_get_contents']);

        $code = '<?php $data = file_get_contents("template.html"); ?>';

        $sandbox->validate($code, 'test.pulse.php');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function customAllowedClassesBypassesDenyList(): void
    {
        $sandbox = new SandboxCompiler(additionalAllowedClasses: ['PDO']);

        $code = '<?php $db = new PDO("sqlite::memory:"); ?>';

        $sandbox->validate($code, 'test.pulse.php');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function deniedFunctionsExcludesAllowed(): void
    {
        $sandbox = new SandboxCompiler(additionalAllowedFunctions: ['system']);

        $denied = $sandbox->deniedFunctions();

        self::assertNotContains('system', $denied);
        self::assertContains('shell_exec', $denied);
    }

    #[Test]
    public function deniedClassesExcludesAllowed(): void
    {
        $sandbox = new SandboxCompiler(additionalAllowedClasses: ['PDO']);

        $denied = $sandbox->deniedClasses();

        self::assertNotContains('PDO', $denied);
        self::assertContains('SQLite3', $denied);
    }

    #[Test]
    public function validateAcceptsEmptyCode(): void
    {
        $this->sandbox->validate('', 'test.pulse.php');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateAcceptsPlainHtml(): void
    {
        $this->sandbox->validate('<div><p>Hello, world!</p></div>', 'test.pulse.php');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function exceptionContainsTemplateName(): void
    {
        try {
            $this->sandbox->validate('<?php shell_exec("ls"); ?>', 'my-template.pulse.php');
            self::fail('Expected exception');
        } catch (ViewException $e) {
            self::assertStringContainsString('my-template.pulse.php', $e->getMessage());
            self::assertStringContainsString('shell_exec', $e->getMessage());
        }
    }
}
