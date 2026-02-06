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
        self::assertArrayHasKey('resources/views/welcome.php', $files);
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

        self::assertArrayNotHasKey('resources/views/welcome.php', $files);
        self::assertArrayNotHasKey('config/security.php', $files);
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

        self::assertArrayNotHasKey('resources/views/welcome.php', $files);
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
        self::assertStringContainsString('welcome.php', $indexPhp);
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

        $welcomeView = $files['resources/views/welcome.php'];
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
        self::assertStringContainsString('/var/cache/', $gitignore);
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
}
