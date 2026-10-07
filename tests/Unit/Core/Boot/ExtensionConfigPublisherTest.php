<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Boot\ExtensionConfigPublisher;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Routing\RouterInterface;

use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rand;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ExtensionConfigPublisher::class)]
final class ExtensionConfigPublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cfgpub_' . rand(100000, 999999);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'config', 0o777, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    #[Test]
    public function publishesTheExtensionsOwnConfigFileUnderANormalisedSection(): void
    {
        $this->writeExtensionConfig('ai-governance', "<?php return ['audit' => true];");

        $registry = $this->publish();

        self::assertTrue(
            $registry->has('ai_governance'),
            'the hyphen in the file name must normalise to the underscore the provider asks for',
        );
        self::assertSame(['audit' => true], $registry->section('ai_governance'));
    }

    #[Test]
    public function theHostConfigFileTakesPrecedenceOverTheExtensionDefault(): void
    {
        $this->writeExtensionConfig('payments', "<?php return ['currency' => 'EUR'];");
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'payments.php',
            "<?php return ['currency' => 'CHF'];",
        );

        self::assertSame(
            ['currency' => 'CHF'],
            $this->publish()->section('payments'),
            'the host has the final word on how an extension it installed is configured',
        );
    }

    #[Test]
    public function anUnknownSectionIsEmptyRatherThanAFailure(): void
    {
        $registry = $this->publish();

        self::assertFalse($registry->has('auth'));
        self::assertSame([], $registry->section('auth'));
    }

    #[Test]
    public function aConfigFileThatReturnsNoArrayFailsInsteadOfYieldingAnEmptyOne(): void
    {
        $this->writeExtensionConfig('grpc', '<?php return 42;');

        $registry = $this->publish();

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageMatches('/must return an array/');
        $registry->section('grpc');
    }

    #[Test]
    public function theSameSectionIsReadFromDiskOnlyOnce(): void
    {
        $file = $this->writeExtensionConfig('form', "<?php return ['reads' => 1];");

        $registry = $this->publish();
        $first = $registry->section('form');

        // Rewriting the file after the first read proves the second call does not
        // re-execute it: registration happens per request, the file must not.
        file_put_contents($file, "<?php return ['reads' => 2];");

        self::assertSame($first, $registry->section('form'));
    }

    #[Test]
    public function theRegistryIsAlwaysBoundSoProvidersNeedNoFallbackPath(): void
    {
        $container = new Container();
        $this->publish($container);

        self::assertTrue($container->has(ExtensionConfigRegistry::class));
    }

    private function writeExtensionConfig(string $name, string $body): string
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . $name . '.php';
        file_put_contents($file, $body);

        return $file;
    }

    private function publish(?ContainerInterface $container = null): ExtensionConfigRegistry
    {
        $container ??= new Container();

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->addExtension(
            new class implements ExtensionInterface {
                public function name(): string
                {
                    return 'test/cfg';
                }

                public function register(ContainerInterface $container): void {}

                public function boot(ContainerInterface $container, RouterInterface $router): void {}

                public function providers(): array
                {
                    return [];
                }
            },
            new ExtensionManifest(
                name: 'test/cfg',
                version: '1.0.0',
                extensionClass: 'stdClass',
                path: $this->root . DIRECTORY_SEPARATOR . 'ext',
            ),
        );

        ExtensionConfigPublisher::publish(
            $bootstrap,
            $this->root . DIRECTORY_SEPARATOR . 'app',
            $container,
        );

        return $container->get(ExtensionConfigRegistry::class);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : unlink($entry);
        }

        rmdir($path);
    }
}
