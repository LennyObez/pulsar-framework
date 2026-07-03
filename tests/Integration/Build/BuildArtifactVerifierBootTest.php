<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Build;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\ArtifactIntegrityVerifier;
use Pulsar\Build\BuildException;
use Pulsar\Build\BuildManifest;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Boot\BuildArtifactVerifier;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function file_put_contents;
use function getenv;
use function hash;
use function is_dir;
use function mkdir;
use function putenv;
use function random_bytes;
use function scandir;
use function strlen;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * FR-15: a signed build manifest must actually be verified at boot.
 *
 * Kernel::boot() runs SecurityWiring (which binds HmacInterface +
 * KeyProviderInterface) before BuildArtifactVerifier::verify(), so the
 * signature is checked rather than silently skipped, and a signed manifest is
 * fail-closed: if the crypto needed to verify it is unavailable, boot is
 * refused instead of falling back to hash-only verification (which an attacker
 * defeats by recomputing hashes after tampering).
 */
#[CoversClass(BuildArtifactVerifier::class)]
final class BuildArtifactVerifierBootTest extends TestCase
{
    /** 32-byte master key (64 hex chars). */
    private const string MASTER_KEY_HEX = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $basePath;
    private string $cacheDir;
    private string $configPath;
    private string|false $prevAppEnv = false;
    private string|false $prevVerify = false;
    private string|false $prevMasterKey = false;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_fr15_' . bin2hex(random_bytes(8));
        $this->configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        $this->cacheDir = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->configPath, 0o750, true);
        mkdir($this->cacheDir, 0o750, true);

        // Minimal config so ConfigManager::load() succeeds and reports production.
        file_put_contents(
            $this->configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'TestApp', 'debug' => false, 'url' => 'http://localhost', 'timezone' => 'UTC'];\n",
        );
        file_put_contents($this->configPath . DIRECTORY_SEPARATOR . 'observability.php', "<?php\nreturn [];\n");
        file_put_contents($this->configPath . DIRECTORY_SEPARATOR . 'security.php', "<?php\nreturn [];\n");

        $this->prevAppEnv = getenv('APP_ENV');
        $this->prevVerify = getenv('PULSAR_VERIFY_ARTIFACTS');
        $this->prevMasterKey = getenv('PULSAR_MASTER_KEY');
        putenv('APP_ENV=production');
        putenv('PULSAR_VERIFY_ARTIFACTS=1');
        // Unset by default; tests opt in to the master-key env-bootstrap path.
        putenv('PULSAR_MASTER_KEY');
    }

    protected function tearDown(): void
    {
        if ($this->prevAppEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->prevAppEnv);
        }

        if ($this->prevVerify === false) {
            putenv('PULSAR_VERIFY_ARTIFACTS');
        } else {
            putenv('PULSAR_VERIFY_ARTIFACTS=' . $this->prevVerify);
        }

        if ($this->prevMasterKey === false) {
            putenv('PULSAR_MASTER_KEY');
        } else {
            putenv('PULSAR_MASTER_KEY=' . $this->prevMasterKey);
        }

        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function signedManifestWithValidSignatureAndHashesPasses(): void
    {
        $content = '<?php return ["compiled" => "genuine"];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $content);
        $this->writeManifest(hash('sha256', $content), strlen($content), $this->sign(hash('sha256', $content), strlen($content)));

        // No exception means the signed manifest verified end to end.
        BuildArtifactVerifier::verify($this->cryptoContainer(), $this->configManager());

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function recomputedHashAttackIsCaughtBySignature(): void
    {
        // The signature is bound to the genuine manifest (hash of the original
        // artifact). The attacker replaces the artifact and recomputes the
        // manifest hash to match, so the HASH check would pass — but the stale
        // signature no longer matches the rewritten manifest.
        $genuine = '<?php return ["compiled" => "genuine"];';
        $signature = $this->sign(hash('sha256', $genuine), strlen($genuine));

        $tampered = '<?php return ["compiled" => "backdoored"];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $tampered);
        $this->writeManifest(hash('sha256', $tampered), strlen($tampered), $signature);

        $this->expectException(BuildException::class);

        BuildArtifactVerifier::verify($this->cryptoContainer(), $this->configManager());
    }

    #[Test]
    public function signedManifestFailsClosedWhenCryptoUnavailable(): void
    {
        // A correctly signed manifest with a matching artifact hash, but neither
        // a container binding nor a master key in the environment. Before the fix
        // this passed on the hash check because signature verification was
        // silently skipped when no crypto was bound; now it must fail closed.
        $content = '<?php return ["compiled" => "genuine"];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $content);
        $this->writeManifest(hash('sha256', $content), strlen($content), $this->sign(hash('sha256', $content), strlen($content)));

        // setUp() unset PULSAR_MASTER_KEY, so the env-bootstrap path finds no key.
        $this->expectException(BuildException::class);

        // Empty container: HmacInterface / KeyProviderInterface are NOT bound.
        BuildArtifactVerifier::verify(new Container(), $this->configManager());
    }

    #[Test]
    public function signedManifestIsVerifiedViaEnvironmentMasterKeyWithoutContainerCrypto(): void
    {
        // The verifier runs before SecurityWiring binds the crypto services, so it
        // must bootstrap them from the master key in the environment. With the key
        // present, a valid signed manifest verifies even with an empty container.
        putenv('PULSAR_MASTER_KEY=' . self::MASTER_KEY_HEX);

        $content = '<?php return ["compiled" => "genuine"];';
        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'config.compiled.php', $content);
        $this->writeManifest(hash('sha256', $content), strlen($content), $this->sign(hash('sha256', $content), strlen($content)));

        BuildArtifactVerifier::verify(new Container(), $this->configManager());

        $this->expectNotToPerformAssertions();
    }

    private function sign(string $hash, int $size): string
    {
        $unsigned = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: ['config' => new ArtifactEntry('config.compiled.php', $hash, $size)],
            contentHashes: [],
        );

        return new ArtifactIntegrityVerifier()->sign($unsigned, new HmacService(), MasterKey::fromHex(self::MASTER_KEY_HEX));
    }

    private function writeManifest(string $hash, int $size, string $signature): void
    {
        $manifest = new BuildManifest(
            version: 1,
            algorithm: 'sha256',
            artifacts: ['config' => new ArtifactEntry('config.compiled.php', $hash, $size)],
            contentHashes: [],
            signature: $signature,
        );

        file_put_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json', $manifest->toJson());
    }

    private function cryptoContainer(): Container
    {
        $container = new Container();
        $container->instance(HmacInterface::class, new HmacService());
        $container->instance(KeyProviderInterface::class, MasterKey::fromHex(self::MASTER_KEY_HEX));

        return $container;
    }

    private function configManager(): ConfigManager
    {
        $configManager = new ConfigManager(configPath: $this->configPath);
        $configManager->load();

        return $configManager;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
