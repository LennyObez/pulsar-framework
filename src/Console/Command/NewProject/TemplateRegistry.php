<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use JsonException;
use Pulsar\Api\Internal;

/**
 * Maps project presets to their file templates.
 *
 * Each preset produces a set of relative-path => content pairs that define
 * the initial file tree of a new Pulsar project.
 */
#[Internal]
final readonly class TemplateRegistry
{
    public function __construct(
        private readonly ComposerJsonGenerator $composerGenerator,
    ) {}

    /**
     * Return all files for the given preset.
     *
     * @return array<string, string> Relative file path => file content
     *
     * @throws JsonException If composer.json encoding fails
     */
    public function getFiles(string $appName, ProjectPreset $preset): array
    {
        $files = $this->commonFiles($appName, $preset);

        return match ($preset) {
            ProjectPreset::Minimal => $files,
            ProjectPreset::Web => [...$files, ...$this->webFiles($appName)],
            ProjectPreset::Api => [...$files, ...$this->apiFiles()],
        };
    }

    /**
     * Files shared by every preset.
     *
     * @return array<string, string>
     *
     * @throws JsonException
     */
    private function commonFiles(string $appName, ProjectPreset $preset): array
    {
        return [
            'public/index.php' => $this->indexPhp($appName, $preset),
            'config/app.php' => $this->appConfig($appName),
            '.gitignore' => $this->gitignore(),
            'composer.json' => $this->composerGenerator->generate($appName, $preset),
        ];
    }

    /**
     * Additional files for the Web preset.
     *
     * @return array<string, string>
     */
    private function webFiles(string $appName): array
    {
        return [
            'resources/views/welcome.php' => $this->welcomeView($appName),
            'config/security.php' => $this->securityConfigWeb(),
        ];
    }

    /**
     * Additional files for the Api preset.
     *
     * @return array<string, string>
     */
    private function apiFiles(): array
    {
        return [
            'src/Http/Controller/HealthController.php' => $this->healthController(),
            'config/security.php' => $this->securityConfigApi(),
        ];
    }

    // ------------------------------------------------------------------
    // Template generators
    // ------------------------------------------------------------------

    private function indexPhp(string $appName, ProjectPreset $preset): string
    {
        return match ($preset) {
            ProjectPreset::Minimal => $this->indexPhpMinimal(),
            ProjectPreset::Web => $this->indexPhpWeb($appName),
            ProjectPreset::Api => $this->indexPhpApi(),
        };
    }

    private function indexPhpMinimal(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            use Pulsar\Core\Kernel;
            use Pulsar\Http\Response;

            $kernel = new Kernel();

            $kernel->router()->get('/', static function (): Response {
                return Response::html('<h1>Hello from Pulsar!</h1>');
            }, 'home');

            $kernel->run();
            PHP;
    }

    private function indexPhpWeb(string $appName): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            use Pulsar\\Config\\ConfigManager;
            use Pulsar\\Core\\Kernel;
            use Pulsar\\Http\\Response;

            \$configManager = new ConfigManager(__DIR__ . '/../config');
            \$kernel = new Kernel(configManager: \$configManager);

            \$kernel->router()->get('/', static function () use (\$kernel): Response {
                \$viewPath = dirname(__DIR__) . '/resources/views/welcome.php';

                ob_start();
                require \$viewPath;
                \$html = ob_get_clean();

                return Response::html(\$html !== false ? \$html : '');
            }, 'home');

            \$kernel->run();
            PHP;
    }

    private function indexPhpApi(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            use App\Http\Controller\HealthController;
            use Pulsar\Config\ConfigManager;
            use Pulsar\Core\Kernel;
            use Pulsar\Http\Response;

            $configManager = new ConfigManager(__DIR__ . '/../config');
            $kernel = new Kernel(configManager: $configManager);

            $kernel->router()->get('/', static function (): Response {
                return Response::json(['message' => 'Pulsar API is running']);
            }, 'home');

            $kernel->router()->get('/health', [HealthController::class, 'index'], 'health');

            $kernel->run();
            PHP;
    }

    private function appConfig(string $appName): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'name' => '$appName',
                'debug' => (bool) (\$_ENV['APP_DEBUG'] ?? true),

                'extensions' => [
                    'paths' => [
                        __DIR__ . '/../extensions',
                    ],
                ],
            ];
            PHP;
    }

    private function gitignore(): string
    {
        return <<<'TEXT'
            /vendor/
            /node_modules/
            /var/cache/
            /var/log/
            /.idea/
            /.vscode/
            .env
            .env.local
            .env.*.local
            *.cache
            *.log
            TEXT;
    }

    private function welcomeView(string $appName): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>$appName</title>
                <style>
                    body {
                        font-family: system-ui, -apple-system, sans-serif;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 100vh;
                        margin: 0;
                        background: #0f172a;
                        color: #e2e8f0;
                    }
                    .container {
                        text-align: center;
                        max-width: 640px;
                        padding: 2rem;
                    }
                    h1 {
                        font-size: 2.5rem;
                        margin-bottom: 0.5rem;
                        background: linear-gradient(135deg, #6366f1, #a855f7);
                        -webkit-background-clip: text;
                        -webkit-text-fill-color: transparent;
                    }
                    p { color: #94a3b8; font-size: 1.1rem; }
                    code {
                        background: #1e293b;
                        padding: 0.2rem 0.5rem;
                        border-radius: 0.25rem;
                        font-size: 0.9rem;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>Welcome to $appName</h1>
                    <p>Powered by the Pulsar Framework.</p>
                    <p>Edit <code>resources/views/welcome.php</code> to get started.</p>
                </div>
            </body>
            </html>
            HTML;
    }

    private function securityConfigWeb(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'session' => [
                    'enabled' => true,
                    'lifetime' => 7200,
                    'cookie_name' => 'pulsar_session',
                    'cookie_secure' => false,
                    'cookie_http_only' => true,
                    'cookie_same_site' => 'Lax',
                ],

                'csrf' => [
                    'enabled' => true,
                    'token_name' => '_token',
                    'header_name' => 'X-CSRF-TOKEN',
                ],

                'headers' => [
                    'x_content_type_options' => 'nosniff',
                    'x_frame_options' => 'DENY',
                    'referrer_policy' => 'strict-origin-when-cross-origin',
                ],
            ];
            PHP;
    }

    private function securityConfigApi(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'session' => [
                    'enabled' => false,
                ],

                'csrf' => [
                    'enabled' => false,
                ],

                'headers' => [
                    'x_content_type_options' => 'nosniff',
                    'x_frame_options' => 'DENY',
                    'referrer_policy' => 'strict-origin-when-cross-origin',
                ],
            ];
            PHP;
    }

    private function healthController(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Http\Controller;

            use Pulsar\Http\Request;
            use Pulsar\Http\Response;

            final class HealthController
            {
                public function index(Request $request): Response
                {
                    return Response::json([
                        'status' => 'healthy',
                        'timestamp' => time(),
                    ]);
                }
            }
            PHP;
    }
}
