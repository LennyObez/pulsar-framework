<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\NewProject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\NewProject\ComposerJsonGenerator;
use Pulsar\Console\Command\NewProject\ProjectPreset;
use Pulsar\Console\Command\NewProject\TemplateRegistry;

#[CoversClass(TemplateRegistry::class)]
final class TemplateRegistryTest extends TestCase
{
    private TemplateRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TemplateRegistry(new ComposerJsonGenerator());
    }

    #[Test]
    public function it_returns_common_files_for_minimal_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        self::assertArrayHasKey('public/index.php', $files);
        self::assertArrayHasKey('config/app.php', $files);
        self::assertArrayHasKey('.gitignore', $files);
        self::assertArrayHasKey('composer.json', $files);
    }

    #[Test]
    public function it_returns_common_files_plus_web_files_for_web_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Web);

        // Common files
        self::assertArrayHasKey('public/index.php', $files);
        self::assertArrayHasKey('config/app.php', $files);
        self::assertArrayHasKey('.gitignore', $files);
        self::assertArrayHasKey('composer.json', $files);

        // Web-specific files
        self::assertArrayHasKey('resources/views/welcome.pulse.php', $files);
        self::assertArrayHasKey('config/security.php', $files);
    }

    #[Test]
    public function it_returns_common_files_plus_api_files_for_api_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Api);

        // Common files
        self::assertArrayHasKey('public/index.php', $files);
        self::assertArrayHasKey('config/app.php', $files);
        self::assertArrayHasKey('.gitignore', $files);
        self::assertArrayHasKey('composer.json', $files);

        // API-specific files
        self::assertArrayHasKey('src/Http/Controller/HealthController.php', $files);
        self::assertArrayHasKey('config/security.php', $files);
    }

    #[Test]
    public function it_does_not_include_web_files_in_minimal_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        self::assertArrayNotHasKey('resources/views/welcome.pulse.php', $files);
    }

    /**
     * `security` is one of ConfigManager::REQUIRED_CONFIGS, so a preset that
     * omits it produces a project that cannot finish loading its own config.
     * The Minimal preset — the one `pulsar init` uses — did exactly that, and
     * this file used to assert the omission as if it were the intent.
     */
    #[Test]
    public function everyPresetIncludesTheRequiredSecurityConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey(
                'config/security.php',
                $files,
                "Preset {$preset->value} must include the required security.php",
            );
        }
    }

    /**
     * Minimal serves HTML from public/index.php, so it takes the browser-facing
     * posture: CSRF stays on unless a preset deliberately turns it off.
     */
    #[Test]
    public function theMinimalPresetShipsTheBrowserFacingSecurityPosture(): void
    {
        $minimal = $this->registry->getFiles('my-app', ProjectPreset::Minimal)['config/security.php'];

        self::assertSame(
            $this->registry->getFiles('my-app', ProjectPreset::Web)['config/security.php'],
            $minimal,
            'Minimal must ship the same posture as Web, not the CSRF-off API one',
        );
        self::assertStringContainsString("'enabled' => true", $minimal);
    }

    #[Test]
    public function it_does_not_include_api_files_in_minimal_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        self::assertArrayNotHasKey('src/Http/Controller/HealthController.php', $files);
    }

    #[Test]
    public function it_does_not_include_api_files_in_web_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Web);

        self::assertArrayNotHasKey('src/Http/Controller/HealthController.php', $files);
    }

    #[Test]
    public function it_does_not_include_web_views_in_api_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Api);

        self::assertArrayNotHasKey('resources/views/welcome.pulse.php', $files);
    }

    #[Test]
    public function it_generates_valid_index_php_for_minimal_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('<?php', $indexPhp);
        self::assertStringContainsString('declare(strict_types=1)', $indexPhp);
        self::assertStringContainsString('Kernel', $indexPhp);
        self::assertStringContainsString('Hello from Pulsar', $indexPhp);
    }

    #[Test]
    public function it_generates_valid_index_php_for_web_preset(): void
    {
        $files = $this->registry->getFiles('my-web-app', ProjectPreset::Web);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('<?php', $indexPhp);
        self::assertStringContainsString('ConfigManager', $indexPhp);
        // The welcome view is generated as its own file; index.php wires the
        // kernel + extensions rather than referencing the view directly.
        self::assertArrayHasKey('resources/views/welcome.pulse.php', $files);
    }

    #[Test]
    public function it_generates_valid_index_php_for_api_preset(): void
    {
        $files = $this->registry->getFiles('my-api', ProjectPreset::Api);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('<?php', $indexPhp);
        self::assertStringContainsString('HealthController', $indexPhp);
        self::assertStringContainsString('/health', $indexPhp);
        self::assertStringContainsString('json', $indexPhp);
    }

    // -- Extension bootstrap and config manager in web/api templates ------

    #[Test]
    public function webPresetIncludesExtensionBootstrap(): void
    {
        $files = $this->registry->getFiles('my-web-app', ProjectPreset::Web);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('ExtensionBootstrap', $indexPhp);
        self::assertStringContainsString('ExtensionBootstrap::create()', $indexPhp);
        self::assertStringContainsString('loadFromPaths($extensionPaths)', $indexPhp);
    }

    #[Test]
    public function apiPresetIncludesExtensionBootstrap(): void
    {
        $files = $this->registry->getFiles('my-api', ProjectPreset::Api);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('ExtensionBootstrap', $indexPhp);
        self::assertStringContainsString('ExtensionBootstrap::create()', $indexPhp);
        self::assertStringContainsString('loadFromPaths($extensionPaths)', $indexPhp);
    }

    #[Test]
    public function webPresetConfigManagerIncludesEnvFilePath(): void
    {
        $files = $this->registry->getFiles('my-web-app', ProjectPreset::Web);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('envFilePath:', $indexPhp);
        self::assertStringContainsString("\$basePath . '/.env'", $indexPhp);
    }

    #[Test]
    public function apiPresetConfigManagerIncludesEnvFilePath(): void
    {
        $files = $this->registry->getFiles('my-api', ProjectPreset::Api);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('envFilePath:', $indexPhp);
        self::assertStringContainsString("\$basePath . '/.env'", $indexPhp);
    }

    #[Test]
    public function webPresetKernelReceivesExtensionBootstrap(): void
    {
        $files = $this->registry->getFiles('my-web-app', ProjectPreset::Web);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('extensionBootstrap:', $indexPhp);
    }

    #[Test]
    public function apiPresetKernelReceivesExtensionBootstrap(): void
    {
        $files = $this->registry->getFiles('my-api', ProjectPreset::Api);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('extensionBootstrap:', $indexPhp);
    }

    #[Test]
    public function webPresetConfigManagerUsesNamedArguments(): void
    {
        $files = $this->registry->getFiles('my-web-app', ProjectPreset::Web);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('configPath:', $indexPhp);
    }

    #[Test]
    public function apiPresetConfigManagerUsesNamedArguments(): void
    {
        $files = $this->registry->getFiles('my-api', ProjectPreset::Api);

        $indexPhp = $files['public/index.php'];
        self::assertStringContainsString('configPath:', $indexPhp);
    }

    #[Test]
    public function it_embeds_the_app_name_in_the_app_config(): void
    {
        $files = $this->registry->getFiles('my-custom-name', ProjectPreset::Minimal);

        $appConfig = $files['config/app.php'];
        self::assertStringContainsString("'name' => 'my-custom-name'", $appConfig);
    }

    #[Test]
    public function it_embeds_the_app_name_in_the_welcome_view(): void
    {
        $files = $this->registry->getFiles('my-web-app', ProjectPreset::Web);

        $welcomeView = $files['resources/views/welcome.pulse.php'];
        self::assertStringContainsString('my-web-app', $welcomeView);
        self::assertStringContainsString('<title>', $welcomeView);
        self::assertStringContainsString('Welcome to', $welcomeView);
    }

    #[Test]
    public function it_generates_a_gitignore_with_common_entries(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        $gitignore = $files['.gitignore'];
        self::assertStringContainsString('/vendor/', $gitignore);
        self::assertStringContainsString('.env', $gitignore);
        self::assertStringContainsString('/var/', $gitignore);
        self::assertStringContainsString('/storage/', $gitignore);
    }

    #[Test]
    public function it_generates_valid_composer_json(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        $composerJson = $files['composer.json'];
        $decoded = json_decode($composerJson, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('name', $decoded);
        self::assertArrayHasKey('require', $decoded);
        self::assertArrayHasKey('autoload', $decoded);
    }

    #[Test]
    public function it_generates_web_security_config_with_sessions_enabled(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Web);

        $securityConfig = $files['config/security.php'];
        self::assertStringContainsString("'enabled' => true", $securityConfig);
        self::assertStringContainsString('csrf', $securityConfig);
        self::assertStringContainsString('session', $securityConfig);
    }

    #[Test]
    public function it_generates_api_security_config_with_sessions_disabled(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Api);

        $securityConfig = $files['config/security.php'];
        self::assertStringContainsString("'enabled' => false", $securityConfig);
    }

    #[Test]
    public function it_generates_health_controller_for_api_preset(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Api);

        $controller = $files['src/Http/Controller/HealthController.php'];
        self::assertStringContainsString('<?php', $controller);
        self::assertStringContainsString('namespace App\\Http\\Controller', $controller);
        self::assertStringContainsString('HealthController', $controller);
        self::assertStringContainsString('healthy', $controller);
    }

    #[Test]
    public function it_returns_all_file_contents_as_strings(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('test-app', $preset);

            foreach ($files as $path => $content) {
                self::assertIsString($content, "File content for '{$path}' must be a string");
                self::assertNotEmpty($content, "File content for '{$path}' must not be empty");
            }
        }
    }

    // ── Config scaffolding tests ───────────────────────────────────────

    #[Test]
    public function allPresetsIncludeDatabaseConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey('config/database.php', $files, "Preset {$preset->value} must include database.php");

            $content = $files['config/database.php'];
            self::assertStringContainsString('sqlite', $content);
            self::assertStringContainsString('DB_CONNECTION', $content);
            self::assertStringContainsString('migrations', $content);
        }
    }

    #[Test]
    public function allPresetsIncludeObservabilityConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey('config/observability.php', $files, "Preset {$preset->value} must include observability.php");

            // The level is a `logging` key. Nested under a channel it was
            // parsed and discarded, and the log ran at the default level while
            // the generated file appeared to set one.
            $content = $files['config/observability.php'];
            self::assertStringContainsString("'logging' => [", $content);
            self::assertStringContainsString('app.log', $content);
            self::assertStringContainsString("'level' => 'debug'", $content);
        }
    }

    #[Test]
    public function allPresetsIncludeI18nConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey('config/i18n.php', $files, "Preset {$preset->value} must include i18n.php");

            // Plural: I18nConfig reads `fallback_locales`, and the singular
            // spelling this template carried was collected as unknown.
            $content = $files['config/i18n.php'];
            self::assertStringContainsString("'default_locale' => 'en'", $content);
            self::assertStringContainsString("'supported_locales'", $content);
            self::assertStringContainsString("'fallback_locales' => ['en']", $content);
        }
    }

    #[Test]
    public function allPresetsIncludeViewConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey('config/view.php', $files, "Preset {$preset->value} must include view.php");

            // The key names ViewConfig actually reads. `paths`/`compiled_path`
            // parsed fine and were discarded, leaving an empty search path.
            $content = $files['config/view.php'];
            self::assertStringContainsString("'template_paths'", $content);
            self::assertStringContainsString("'cache_path'", $content);
        }
    }

    #[Test]
    public function allPresetsIncludeCacheConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey('config/cache.php', $files, "Preset {$preset->value} must include cache.php");

            // CacheConfig reads enabled/default_pool/path/pools; the older
            // `default`/`stores` pair was collected as unknown and ignored.
            $content = $files['config/cache.php'];
            self::assertStringContainsString("'default_pool' => 'default'", $content);
            self::assertStringContainsString("'pools'", $content);
        }
    }

    #[Test]
    public function allPresetsIncludeMailConfig(): void
    {
        foreach (ProjectPreset::cases() as $preset) {
            $files = $this->registry->getFiles('my-app', $preset);

            self::assertArrayHasKey('config/mail.php', $files, "Preset {$preset->value} must include mail.php");

            // MailConfig reads default_driver/default_from_address, not the
            // `default`/`mailers`/`from` trio this template used to emit.
            $content = $files['config/mail.php'];
            self::assertStringContainsString("'default_driver' => 'log'", $content);
            self::assertStringContainsString("'default_from_address'", $content);
        }
    }

    #[Test]
    public function databaseConfigDefaultsToSqliteWithEnvOverride(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        $content = $files['config/database.php'];
        // Default connection is sqlite
        self::assertStringContainsString("'default' =>", $content);
        self::assertStringContainsString("'sqlite'", $content);
        // Respects DB_CONNECTION env var
        self::assertStringContainsString('DB_CONNECTION', $content);
        // Includes database path
        self::assertStringContainsString('database.sqlite', $content);
    }

    #[Test]
    public function mailConfigUsesLogDriverByDefault(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        $content = $files['config/mail.php'];
        self::assertStringContainsString("'default_driver' => 'log'", $content);
        self::assertStringContainsString("'default_from_name' => 'Pulsar App'", $content);
        self::assertStringContainsString("'default_from_address' => 'noreply@example.com'", $content);
    }

    #[Test]
    public function allConfigFilesReturnArrays(): void
    {
        $files = $this->registry->getFiles('my-app', ProjectPreset::Minimal);

        $configFiles = [
            'config/app.php',
            'config/security.php',
            'config/extensions.php',
            'config/database.php',
            'config/observability.php',
            'config/i18n.php',
            'config/view.php',
            'config/cache.php',
            'config/mail.php',
        ];

        foreach ($configFiles as $configFile) {
            self::assertArrayHasKey($configFile, $files, "Missing {$configFile}");
            self::assertStringContainsString('return [', $files[$configFile], "{$configFile} must return an array");
            self::assertStringContainsString('declare(strict_types=1)', $files[$configFile], "{$configFile} must declare strict_types");
        }
    }
}
