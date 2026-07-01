<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Core\Kernel;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\Http\Message\ServerRequest;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_file;
use function ltrim;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function uniqid;

/**
 * A request that throws must leave a record in the configured log sink. The
 * flat `logging.driver`/`path` config shape previously produced no sink at all,
 * so every unhandled-exception entry was silently dropped and the log file was
 * never created — operators had no trace of production 500s.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(ObservabilityConfig::class)]
final class ExceptionLoggingTest extends TestCase
{
    private string $tempDir;
    private string $logPath;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->tempDir = $cwd . '/var/tmp_pulsar_exc_log_' . uniqid();
        mkdir($this->tempDir . '/config', 0o775, true);
        $this->logPath = $this->tempDir . '/app.log';

        file_put_contents($this->tempDir . '/config/app.php', "<?php return ['name' => 'TestApp', 'env' => 'production', 'debug' => false, 'timezone' => 'UTC', 'locale' => 'en'];");
        file_put_contents($this->tempDir . '/config/security.php', '<?php return ["session" => [], "csrf" => ["enabled" => false], "headers" => [], "rate_limiting" => ["enabled" => false]];');
        // Flat shape (driver/path, no `channels` map) — exactly the config that
        // previously yielded no sink.
        file_put_contents(
            $this->tempDir . '/config/observability.php',
            '<?php return ["logging" => ["driver" => "file", "path" => ' . var_export($this->logPath, true) . ', "level" => "debug"]];',
        );
    }

    protected function tearDown(): void
    {
        $cwd = getcwd();
        if ($cwd === false || !is_dir($this->tempDir)) {
            return;
        }

        $relative = str_starts_with($this->tempDir, $cwd)
            ? ltrim(substr($this->tempDir, strlen($cwd)), '/\\')
            : $this->tempDir;
        $safe = SafePath::resolveUnderCwd($relative);

        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    #[Test]
    public function aThrowingRequestWritesOneErrorEntryToTheConfiguredFileSink(): void
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir . '/config'));
        $kernel->boot();
        $kernel->router()->get('/boom', static fn(): never => throw new RuntimeException('kaboom in production'));

        self::assertFileDoesNotExist($this->logPath);

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/boom'));

        self::assertSame(500, $response->getStatusCode());

        // The sink exists, was created, and holds exactly one error record
        // naming the exception (class + message), independent of APP_DEBUG.
        self::assertTrue(is_file($this->logPath), 'configured log file must be created');
        $contents = (string) file_get_contents($this->logPath);
        self::assertStringContainsString('"level":"error"', $contents);
        self::assertStringContainsString('kaboom in production', $contents);
        self::assertStringContainsString('RuntimeException', $contents);
        // Exactly one error entry — the top-level "level":"error" appears once
        // per log line (the serialized exception context carries no level key).
        self::assertSame(1, substr_count($contents, '"level":"error"'));
    }
}
