<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function glob;
use function json_decode;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Guards the completeness of config/extensions.php against the bundled
 * extensions on disk.
 *
 * The capability sandbox (ExtensionSandbox) caps any extension absent from the
 * trusted list at Community. Community cannot write the container, so a bundled
 * first-party extension that registers services would fail to boot if it were
 * ever missing from the list. This test fails the build the moment a bundled
 * manifest is added (or renamed) without a matching core-tier entry, so the
 * list — and therefore the sandbox posture — can never silently drift.
 */
final class ExtensionSandboxDriftTest extends TestCase
{
    #[Test]
    public function everyBundledExtensionIsTrustedAtCoreTier(): void
    {
        $root = dirname(__DIR__, 4);

        $trusted = $this->trustedExtensions($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php');

        $manifests = glob($root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'pulsar.json');
        self::assertIsArray($manifests);
        self::assertNotEmpty($manifests, 'expected bundled extension manifests to exist');

        foreach ($manifests as $manifestPath) {
            $name = $this->manifestName($manifestPath);

            self::assertArrayHasKey(
                $name,
                $trusted,
                sprintf(
                    'Bundled extension "%s" is missing from config/extensions.php trusted_extensions. '
                    . 'Add it at core tier, or the sandbox will cap it at community and it will fail to boot.',
                    $name,
                ),
            );

            self::assertSame(
                'core',
                $trusted[$name]['tier'] ?? null,
                sprintf('Bundled extension "%s" must be trusted at core tier.', $name),
            );
        }
    }

    /**
     * @return array<string, array{tier?: string}>
     */
    private function trustedExtensions(string $configFile): array
    {
        $data = require $configFile;
        self::assertIsArray($data);
        self::assertArrayHasKey('trusted_extensions', $data);
        self::assertIsArray($data['trusted_extensions']);

        /** @var array<string, array{tier?: string}> */
        return $data['trusted_extensions'];
    }

    private function manifestName(string $manifestPath): string
    {
        $raw = file_get_contents($manifestPath);
        self::assertIsString($raw, sprintf('unable to read %s', $manifestPath));

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, sprintf('invalid JSON in %s', $manifestPath));
        self::assertArrayHasKey('name', $decoded, sprintf('manifest %s has no name', $manifestPath));

        $name = $decoded['name'];
        self::assertIsString($name);

        return $name;
    }
}
