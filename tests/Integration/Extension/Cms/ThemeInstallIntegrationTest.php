<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;
use Pulsar\Extension\Cms\Config\ThemesConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Themes\SafeArchiveExtractor;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManager;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManifestValidator;
use Pulsar\Extension\Cms\Internal\Themes\ThemeProvenanceVerifier;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;
use ZipArchive;

use function array_values;
use function base64_encode;
use function bin2hex;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function random_bytes;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ThemeManager::class)]
#[CoversClass(SafeArchiveExtractor::class)]
#[CoversClass(ThemeManifestValidator::class)]
#[CoversClass(ThemeProvenanceVerifier::class)]
final class ThemeInstallIntegrationTest extends TestCase
{
    private string $tmpDir;
    private string $storageDir;
    private InMemoryThemeRepository $themeRepo;
    private NullEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pulsar_theme_integ_' . bin2hex(random_bytes(4));
        $this->storageDir = $this->tmpDir . '/storage/cms/themes';
        mkdir($this->tmpDir, 0o777, true);
        mkdir($this->storageDir, 0o777, true);

        $this->themeRepo = new InMemoryThemeRepository();
        $this->eventDispatcher = new NullEventDispatcher();
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    // -- Install creates DB record and extracts files -------------------------

    #[Test]
    public function installCreatesDbRecordAndExtractsFiles(): void
    {
        $archivePath = $this->createValidThemeArchive('test-theme', '1.0.0');
        $manager = $this->createManager(requireSigned: false);

        $theme = $manager->install($archivePath, 'user-001');

        // DB record
        self::assertSame('test-theme', $theme->slug);
        self::assertSame('Test theme', $theme->displayName);
        self::assertSame('1.0.0', $theme->version);
        self::assertFalse($theme->isActive);
        self::assertSame('user-001', $theme->installedBy);

        // Theme persisted to repository
        $found = $this->themeRepo->findBySlug('test-theme');
        self::assertNotNull($found);
        self::assertSame($theme->id, $found->id);

        // Files extracted to storage
        self::assertTrue(is_dir($theme->storagePath));
        self::assertTrue(file_exists($theme->storagePath . '/theme.json'));
    }

    // -- Activate sets is_active and deploys assets ---------------------------

    #[Test]
    public function activateSetsIsActive(): void
    {
        $archivePath = $this->createValidThemeArchive('active-theme', '1.0.0');
        $manager = $this->createManager(requireSigned: false);

        $installed = $manager->install($archivePath, 'user-001');
        $activated = $manager->activate($installed->id, 'user-001');

        self::assertTrue($activated->isActive);
        self::assertNotNull($activated->activatedAt);
        self::assertSame('user-001', $activated->activatedBy);

        // Verify in repository
        $found = $this->themeRepo->findById($activated->id);
        self::assertNotNull($found);
        self::assertTrue($found->isActive);
    }

    #[Test]
    public function activateDeactivatesPreviousActiveTheme(): void
    {
        $manager = $this->createManager(requireSigned: false);

        $archiveA = $this->createValidThemeArchive('theme-a', '1.0.0');
        $archiveB = $this->createValidThemeArchive('theme-b', '1.0.0');

        $themeA = $manager->install($archiveA, 'user-001');
        $themeB = $manager->install($archiveB, 'user-001');

        $manager->activate($themeA->id, 'user-001');
        $manager->activate($themeB->id, 'user-001');

        $foundA = $this->themeRepo->findById($themeA->id);
        $foundB = $this->themeRepo->findById($themeB->id);

        self::assertNotNull($foundA);
        self::assertNotNull($foundB);
        self::assertFalse($foundA->isActive);
        self::assertTrue($foundB->isActive);
    }

    // -- Delete soft-deletes and removes files --------------------------------

    #[Test]
    public function deleteRemovesFromRepository(): void
    {
        $archivePath = $this->createValidThemeArchive('deletable', '1.0.0');
        $manager = $this->createManager(requireSigned: false);

        $theme = $manager->install($archivePath, 'user-001');
        $storagePath = $theme->storagePath;

        self::assertTrue(is_dir($storagePath));

        $manager->delete($theme->id, 'user-001', 'No longer needed');

        // Record deleted from repository
        $found = $this->themeRepo->findById($theme->id);
        self::assertNull($found);

        // Storage directory removed
        self::assertFalse(is_dir($storagePath));
    }

    #[Test]
    public function cannotDeleteActiveTheme(): void
    {
        $archivePath = $this->createValidThemeArchive('active-del', '1.0.0');
        $manager = $this->createManager(requireSigned: false);

        $theme = $manager->install($archivePath, 'user-001');
        $manager->activate($theme->id, 'user-001');

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Cannot delete active theme');
        $manager->delete($theme->id, 'user-001', 'test');
    }

    // -- Unsigned theme rejected when requireSignedThemes=true ----------------

    #[Test]
    public function unsignedThemeRejectedWhenSignedRequired(): void
    {
        $archivePath = $this->createValidThemeArchive('unsigned-theme', '1.0.0');
        $manager = $this->createManager(requireSigned: true, trustedKeys: []);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Provenance verification failed');
        $manager->install($archivePath, 'user-001');
    }

    // -- Signed theme accepted ------------------------------------------------

    #[Test]
    public function signedThemeAccepted(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $archivePath = $this->createValidThemeArchive('signed-theme', '2.0.0');

        // Create signature file
        $archiveContent = file_get_contents($archivePath);
        self::assertIsString($archiveContent, 'Failed to read archive file');
        $signature = sodium_crypto_sign_detached($archiveContent, $secretKey);
        file_put_contents($archivePath . '.sig', base64_encode($signature));

        $manager = $this->createManager(
            requireSigned: true,
            trustedKeys: [base64_encode($publicKey)],
        );

        $theme = $manager->install($archivePath, 'user-001');

        self::assertSame('signed-theme', $theme->slug);
        self::assertTrue($theme->provenanceVerified);
        self::assertTrue($theme->signatureVerified);
    }

    // -- Tampered archive rejected --------------------------------------------

    #[Test]
    public function tamperedArchiveRejected(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Create and sign the archive
        $archivePath = $this->createValidThemeArchive('tampered', '1.0.0');
        $archiveContent = file_get_contents($archivePath);
        self::assertIsString($archiveContent, 'Failed to read archive file');
        $signature = sodium_crypto_sign_detached($archiveContent, $secretKey);
        file_put_contents($archivePath . '.sig', base64_encode($signature));

        // Tamper with the archive after signing
        file_put_contents($archivePath, $archiveContent . 'TAMPERED');

        $manager = $this->createManager(
            requireSigned: true,
            trustedKeys: [base64_encode($publicKey)],
        );

        $this->expectException(CmsException::class);
        $manager->install($archivePath, 'user-001');
    }

    // -- Invalid manifest rejected --------------------------------------------

    #[Test]
    public function archiveWithoutManifestRejected(): void
    {
        // Create a zip without theme.json
        $zipPath = $this->tmpDir . '/no-manifest.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'No manifest here');
        $zip->close();

        $manager = $this->createManager(requireSigned: false);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('theme.json');
        $manager->install($zipPath, 'user-001');
    }

    // -- Rollback to previous theme -------------------------------------------

    #[Test]
    public function rollbackActivatesPreviouslyDeactivatedTheme(): void
    {
        $manager = $this->createManager(requireSigned: false);

        $archiveA = $this->createValidThemeArchive('rollback-a', '1.0.0');
        $archiveB = $this->createValidThemeArchive('rollback-b', '1.0.0');

        $themeA = $manager->install($archiveA, 'user-001');
        $themeB = $manager->install($archiveB, 'user-001');

        $manager->activate($themeA->id, 'user-001');
        $manager->activate($themeB->id, 'user-001');

        // Now themeA is deactivated, themeB is active
        // Rollback should reactivate themeA
        $rolledBack = $manager->rollback('user-001');

        self::assertSame($themeA->id, $rolledBack->id);
        self::assertTrue($rolledBack->isActive);
    }

    // -- Helpers --------------------------------------------------------------

    /** @param list<string> $trustedKeys */
    private function createManager(
        bool $requireSigned = false,
        array $trustedKeys = [],
    ): ThemeManager {
        $config = new ThemesConfig(
            storagePath: $this->storageDir,
            requireSignedThemes: $requireSigned,
            trustedPublicKeys: array_values($trustedKeys),
        );

        return new ThemeManager(
            $this->themeRepo,
            new ThemeManifestValidator(),
            new ThemeProvenanceVerifier($config, new NullLogger()),
            new SafeArchiveExtractor($config, new NullLogger()),
            $config,
            $this->eventDispatcher,
            null,
            new NullLogger(),
            $this->createStub(PreviewSessionRepositoryInterface::class),
        );
    }

    private function createValidThemeArchive(string $slug, string $version): string
    {
        $manifest = json_encode([
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'version' => $version,
            'description' => "Test theme {$slug}",
            'author_name' => 'Test Author',
            'license' => 'MIT',
            'regions' => ['header', 'content', 'footer'],
        ], JSON_THROW_ON_ERROR);

        $zipPath = $this->tmpDir . '/' . $slug . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('theme.json', $manifest);
        $zip->addFromString('templates/article.pulse.php', '<?php // article template');
        $zip->addFromString('assets/style.css', 'body { color: #333; }');
        $zip->close();

        return $zipPath;
    }

    private function recursiveDelete(string $dir): void
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

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

// -- Test doubles for theme install integration tests -------------------------

final class InMemoryThemeRepository implements ThemeRepositoryInterface
{
    /** @var array<string, InstalledTheme> */
    private array $themes = [];

    public function findById(string $id): ?InstalledTheme
    {
        return $this->themes[$id] ?? null;
    }

    public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledTheme
    {
        foreach ($this->themes as $theme) {
            if ($theme->slug === $slug) {
                return $theme;
            }
        }

        return null;
    }

    public function findActive(?string $tenantId = null): ?InstalledTheme
    {
        foreach ($this->themes as $theme) {
            if ($theme->isActive) {
                return $theme;
            }
        }

        return null;
    }

    public function findAll(?string $tenantId = null): array
    {
        return array_values($this->themes);
    }

    public function save(InstalledTheme $theme): void
    {
        $this->themes[$theme->id] = $theme;
    }

    public function delete(string $themeId): void
    {
        unset($this->themes[$themeId]);
    }
}

final class NullEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope
    {
        return $envelope;
    }
}
