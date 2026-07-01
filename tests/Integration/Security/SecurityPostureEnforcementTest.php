<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Core\Wiring\SecurityPostureWiring;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\Security\Posture\SecurityPostureException;
use Pulsar\Security\Posture\SecurityPostureReport;

use function file_put_contents;
use function getcwd;
use function is_dir;
use function ltrim;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;

/**
 * The production security-posture preflight must fail LOUD: with enforcement
 * enabled, a blocking item (here, debug mode left on in production) aborts boot
 * instead of letting the app start weakened. With enforcement off it boots but
 * the report — bound for `security:check` and the health endpoint — records the
 * failure.
 */
#[CoversClass(SecurityPostureWiring::class)]
#[CoversClass(SecurityPostureException::class)]
final class SecurityPostureEnforcementTest extends TestCase
{
    private const string STRONG_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $tempDir;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->tempDir = $cwd . '/var/tmp_pulsar_posture_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        // Production + debug on = a guaranteed blocking FAIL (debug_mode).
        file_put_contents($this->tempDir . '/app.php', "<?php return ['name' => 'TestApp', 'env' => 'production', 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en'];");
        file_put_contents($this->tempDir . '/observability.php', '<?php return ["logging" => ["default_channel" => "null", "level" => "debug", "channels" => ["null" => ["driver" => "stream", "stream" => "php://memory"]]]];');
        file_put_contents($this->tempDir . '/security.php', '<?php return ["session" => ["cookie_secure" => true], "csrf" => ["enabled" => true], "headers" => [], "rate_limiting" => ["enabled" => false]];');

        putenv('APP_ENV=production');
        putenv('PULSAR_MASTER_KEY=' . self::STRONG_KEY);
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('PULSAR_MASTER_KEY');
        putenv('PULSAR_SECURITY_POSTURE_ENFORCE');

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

    private function kernel(): Kernel
    {
        return new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
    }

    #[Test]
    public function enforcementAbortsBootOnABlockingItem(): void
    {
        putenv('PULSAR_SECURITY_POSTURE_ENFORCE=true');

        $this->expectException(SecurityPostureException::class);
        $this->expectExceptionMessage('debug_mode');

        $this->kernel()->boot();
    }

    #[Test]
    public function withoutEnforcementBootSucceedsButReportRecordsTheFailure(): void
    {
        // Enforcement env var intentionally unset.
        $kernel = $this->kernel();
        $kernel->boot();

        self::assertTrue($kernel->container()->has(SecurityPostureReport::class));

        /** @var SecurityPostureReport $report */
        $report = $kernel->container()->get(SecurityPostureReport::class);
        self::assertTrue($report->hasFailures(), 'Posture report must record the debug-in-production failure');
    }
}
