<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;
use Pulsar\Deploy\Check\IntegrityCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Integrity\ManifestVerifier;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(IntegrityCheck::class)]
final class IntegrityCheckTest extends TestCase
{
    private const string MANIFEST_PATH = 'var/integrity/manifest.json';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integrity_check_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_returns_name(): void
    {
        self::assertSame('integrity', $this->buildCheck(enabled: false)->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        self::assertSame(
            'Verifies the signed integrity manifest against the files on disk',
            $this->buildCheck(enabled: false)->getDescription(),
        );
    }

    #[Test]
    public function it_passes_when_the_signed_manifest_matches_the_filesystem(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();

        $result = $this->buildCheck(enabled: true, mode: IntegrityPolicyMode::Strict)->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('Integrity manifest verified', $result->message);
        self::assertStringContainsString('strict', $result->message);
    }

    #[Test]
    public function it_errors_in_production_when_a_covered_file_was_modified(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();
        $this->createFile('src/Kernel.php', '<?php class Kernel { /* tampered */ }');

        $result = $this->buildCheck(enabled: true)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('1 modified', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_errors_in_production_when_a_file_was_added_to_a_covered_directory(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();
        $this->createFile('src/shell.php', '<?php system($_GET["c"]);');

        $result = $this->buildCheck(enabled: true)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('1 added', $result->message);
    }

    #[Test]
    public function it_errors_in_production_when_the_manifest_is_absent(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');

        $result = $this->buildCheck(enabled: true)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('Integrity manifest not found', $result->message);
        self::assertStringContainsString('integrity:build', implode(' ', $result->recommendations));
    }

    #[Test]
    public function it_errors_in_production_when_the_manifest_is_corrupt(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->createFile(self::MANIFEST_PATH, '{ not json');

        $result = $this->buildCheck(enabled: true)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('Integrity manifest is corrupted', $result->message);
    }

    #[Test]
    public function it_errors_in_production_when_the_manifest_is_unsigned(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeManifest(signature: null);

        $result = $this->buildCheck(enabled: true)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('unsigned', $result->message);
    }

    #[Test]
    public function it_errors_in_production_when_the_signature_is_invalid(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeManifest(signature: 'forged');

        $result = $this->buildCheck(enabled: true, signatureValid: false)->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('signature is invalid', $result->message);
    }

    #[Test]
    public function it_errors_in_production_when_no_signing_key_is_available(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();

        $check = new IntegrityCheck(
            $this->buildConfig(enabled: true),
            new ManifestVerifier($this->tempDir),
            null,
            $this->tempDir,
        );

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('no signing key is available', $result->message);
    }

    #[Test]
    public function it_errors_in_production_when_no_verifier_was_wired(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();

        $check = new IntegrityCheck(
            $this->buildConfig(enabled: true),
            null,
            $this->signerStub(true),
            $this->tempDir,
        );

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('no manifest verifier is available', $result->message);
    }

    #[Test]
    public function it_warns_outside_production_when_verification_fails(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();
        $this->createFile('src/Kernel.php', '<?php class Kernel { /* tampered */ }');

        $check = $this->buildCheck(enabled: true);

        self::assertSame(CheckSeverity::Warning, $check->check('staging')->severity);
        self::assertSame(CheckSeverity::Warning, $check->check('local')->severity);
    }

    #[Test]
    public function it_errors_when_integrity_disabled_in_production(): void
    {
        $result = $this->buildCheck(enabled: false)->check('production');

        // Production fail-closes on missing integrity verification — a
        // deploy-gating Error, not a soft warning operators routinely ignore
        // (PCI Req 11, HIPAA §164.312(c)(1)).
        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('File integrity verification is disabled', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_integrity_disabled_in_staging(): void
    {
        $result = $this->buildCheck(enabled: false)->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('File integrity verification is disabled', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_passes_when_integrity_disabled_in_local(): void
    {
        $result = $this->buildCheck(enabled: false)->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function production_recommendations_mention_config_and_manifest(): void
    {
        $result = $this->buildCheck(enabled: false)->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('config/integrity.php', $joined);
        self::assertStringContainsString('php bin/pulsar optimize', $joined);
    }

    #[Test]
    public function it_includes_warn_mode_in_pass_message(): void
    {
        $this->createFile('src/Kernel.php', '<?php class Kernel {}');
        $this->writeSignedManifest();

        $result = $this->buildCheck(enabled: true, mode: IntegrityPolicyMode::Warn)->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('warn', $result->message);
    }

    private function buildCheck(
        bool $enabled,
        IntegrityPolicyMode $mode = IntegrityPolicyMode::Warn,
        bool $signatureValid = true,
    ): IntegrityCheck {
        return new IntegrityCheck(
            $this->buildConfig($enabled, $mode),
            new ManifestVerifier($this->tempDir),
            $this->signerStub($signatureValid),
            $this->tempDir,
        );
    }

    private function buildConfig(bool $enabled, IntegrityPolicyMode $mode = IntegrityPolicyMode::Warn): IntegrityConfig
    {
        return new IntegrityConfig(
            enabled: $enabled,
            manifestPath: self::MANIFEST_PATH,
            mode: $mode,
        );
    }

    private function signerStub(bool $signatureValid): ManifestSignerInterface
    {
        $signer = $this->createStub(ManifestSignerInterface::class);
        $signer->method('verify')->willReturn($signatureValid);
        $signer->method('sign')->willReturn('stub-signature');

        return $signer;
    }

    private function writeSignedManifest(): void
    {
        $this->writeManifest(signature: 'valid-signature');
    }

    private function writeManifest(?string $signature): void
    {
        $manifest = new ManifestBuilder($this->tempDir)->build(['src/**/*.php'], []);

        $this->createFile(self::MANIFEST_PATH, ManifestFormat::toJson($manifest, $signature));
    }

    private function createFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($absolutePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        file_put_contents($absolutePath, $content);
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

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
