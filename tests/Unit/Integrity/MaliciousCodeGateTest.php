<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function dirname;
use function fclose;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

/**
 * Proves the ASVS 10.1.1 gate can fail.
 *
 * The audit that produced this gate found four controls that passed their own
 * tests while being broken, and one of them was a lint whose strict variant
 * selected an empty rule set and therefore exited 0 forever. So the thing worth
 * asserting here is not that the framework is clean — CI asserts that by running
 * the gate — but that a planted backdoor makes the gate exit non-zero, that a
 * scan which reaches nothing is a failure rather than a pass, and that the
 * reviewed-exception list cannot excuse a file it does not name.
 *
 * The planted samples below are string literals: this test writes them to a
 * temporary directory and points the gate at it with --root. Nothing here is
 * ever included, evaluated or executed by PHP — the gate reads the fixtures as
 * text, and the directory is removed in tearDown().
 */
final class MaliciousCodeGateTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../tools/security/assert-no-malicious-code.php';

    private string $fixtureRoot = '';

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/pulsar-malicious-gate-' . bin2hex(random_bytes(6));

        mkdir($this->fixtureRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->fixtureRoot);
    }

    #[Test]
    public function itFailsOnAPlantedWebshell(): void
    {
        $this->write('nested/handler.php', '<?php @eval($_POST["c"]);');

        [$status, , $stderr] = $this->invokeGate();

        self::assertSame(1, $status, "gate exited 0 on a planted webshell:\n" . $stderr);
        self::assertStringContainsString('nested/handler.php', $stderr);
        self::assertStringContainsString('[eval]', $stderr);
    }

    #[Test]
    public function itFailsOnAnObfuscatedLoader(): void
    {
        $this->write('loader.php', '<?php $h = base64_decode($_GET["p"]); $h($_GET["a"]);');

        [$status, , $stderr] = $this->invokeGate();

        self::assertSame(1, $status, "gate exited 0 on an obfuscated loader:\n" . $stderr);
        self::assertStringContainsString('[obfuscated-execution]', $stderr);
    }

    #[Test]
    public function itPassesOnATreeOfBenignLookalikes(): void
    {
        $this->write('Pdo.php', '<?php $pdo->exec("SELECT 1"); $redis?->eval($lua, $keys, 2);');
        $this->write('Csprng.php', '<?php $n = random_int(0, 10); $b = random_bytes(32);');

        [$status, $stdout, $stderr] = $this->invokeGate();

        self::assertSame(0, $status, "gate failed on clean code:\n" . $stderr);
        self::assertStringContainsString('Scanned 2 PHP files.', $stdout);
    }

    /**
     * The allowance is bound to a path, not to a pattern. A file that borrows the
     * name of a reviewed one gets nothing from it — otherwise the list would be a
     * way of naming patterns the gate stops looking for.
     */
    #[Test]
    public function itAppliesNoReviewedExceptionToAForeignTree(): void
    {
        $this->write(
            'src/Console/InteractivePrompt.php',
            '<?php proc_open($a, [], $p); proc_open($b, [], $q);',
        );

        [$status, , $stderr] = $this->invokeGate();

        self::assertSame(1, $status, "a reviewed path leaked its allowance to a foreign tree:\n" . $stderr);
        self::assertStringContainsString('[process-spawn]', $stderr);
    }

    #[Test]
    public function itRefusesAScanThatReachedNothing(): void
    {
        [$status, , $stderr] = $this->invokeGate();

        self::assertSame(2, $status, 'an empty tree was reported as clean');
        self::assertStringContainsString('matched no PHP files', $stderr);
    }

    #[Test]
    public function itRefusesAnUnknownArgument(): void
    {
        $this->write('ok.php', '<?php $x = 1;');

        [$status, , $stderr] = $this->invokeGate('--sevrity=ERROR');

        self::assertSame(2, $status, 'a mistyped argument was accepted');
        self::assertStringContainsString('unknown argument', $stderr);
    }

    #[Test]
    public function itRefusesARootThatIsNotADirectory(): void
    {
        $this->write('ok.php', '<?php $x = 1;');

        [$status, , $stderr] = $this->runGate([
            PHP_BINARY,
            self::SCRIPT,
            '--root=' . $this->fixtureRoot . '/ok.php',
        ]);

        self::assertSame(2, $status, 'a file was accepted as a scan root');
        self::assertStringContainsString('--root is not a directory', $stderr);
    }

    private function write(string $relative, string $source): void
    {
        $path = $this->fixtureRoot . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        file_put_contents($path, $source);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function invokeGate(string ...$extra): array
    {
        // array_values, because proc_open wants a list and spreading a variadic does
        // not guarantee one — a named argument would give it a string key.
        return $this->runGate(array_values(
            [PHP_BINARY, self::SCRIPT, '--root=' . $this->fixtureRoot, ...$extra],
        ));
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runGate(array $command): array
    {
        // Descriptor 0 gets its own pipe and is closed at once. An unspecified
        // descriptor is inherited, and under a parallel runner the inherited stdin
        // is the pipe the runner uses to feed its worker.
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process, 'could not start the gate');

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                rmdir($entry->getPathname());

                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
