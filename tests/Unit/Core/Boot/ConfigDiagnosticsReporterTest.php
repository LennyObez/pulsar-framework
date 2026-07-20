<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Boot\ConfigDiagnosticsReporter;
use Pulsar\Extensibility\ExtensionBootstrap;
use Stringable;

use function bin2hex;
use function file_put_contents;
use function implode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ConfigDiagnosticsReporter::class)]
final class ConfigDiagnosticsReporterTest extends TestCase
{
    private string $projectRoot;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
        $this->projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cfgdiag_' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/config', 0o750, true);
        mkdir($this->projectRoot . '/extensions/cms', 0o750, true);

        file_put_contents(
            $this->projectRoot . '/extensions/cms/pulsar.json',
            (string) json_encode([
                'name' => 'test/cms',
                'version' => '1.0.0',
                'extension_class' => 'Pulsar\\NonExistent\\CmsExtension',
                'pulsar' => ['min_version' => '0.1.0'],
            ]),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/config/cms.php');
        @unlink($this->projectRoot . '/extensions/cms/pulsar.json');
        @rmdir($this->projectRoot . '/config');
        @rmdir($this->projectRoot . '/extensions/cms');
        @rmdir($this->projectRoot . '/extensions');
        @rmdir($this->projectRoot);
    }

    #[Test]
    public function warnsWhenAConfigFileBelongsToADisabledExtension(): void
    {
        file_put_contents($this->projectRoot . '/config/cms.php', "<?php return [];\n");

        ConfigDiagnosticsReporter::report(
            $this->configManager(),
            $this->bootstrapWithCmsDisabled(),
            $this->collectingLogger(),
        );

        $hit = false;
        foreach ($this->warnings as $w) {
            if (str_contains($w, 'config/cms.php') && str_contains($w, 'test/cms')) {
                $hit = true;
            }
        }

        self::assertTrue($hit, 'expected a warning tying config/cms.php to the disabled extension. Got: ' . implode(' | ', $this->warnings));
    }

    #[Test]
    public function doesNotWarnAboutTheConfigFileWhenTheDisabledExtensionHasNone(): void
    {
        // No config/cms.php on disk — the disabled extension is fine, nothing to flag.
        ConfigDiagnosticsReporter::report(
            $this->configManager(),
            $this->bootstrapWithCmsDisabled(),
            $this->collectingLogger(),
        );

        foreach ($this->warnings as $w) {
            self::assertStringNotContainsString('config/cms.php', $w);
        }
    }

    #[Test]
    public function isANoOpWithoutALogger(): void
    {
        file_put_contents($this->projectRoot . '/config/cms.php', "<?php return [];\n");

        // Must not throw when no logger is available (e.g. a bare kernel).
        ConfigDiagnosticsReporter::report($this->configManager(), $this->bootstrapWithCmsDisabled(), null);

        self::assertSame([], $this->warnings);
    }

    private function configManager(): ConfigManager
    {
        return new ConfigManager(configPath: $this->projectRoot . '/config');
    }

    private function bootstrapWithCmsDisabled(): ExtensionBootstrap
    {
        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->setEnabledFilter([]); // disable everything, including test/cms
        $bootstrap->loadFromPaths([$this->projectRoot . '/extensions']);

        return $bootstrap;
    }

    private function collectingLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string|Stringable $message): void {
                $this->warnings[] = (string) $message;
            },
        );

        return $logger;
    }
}
