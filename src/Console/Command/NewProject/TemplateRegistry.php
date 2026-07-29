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
        private ComposerJsonGenerator $composerGenerator,
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
            'config/database.php' => $this->databaseConfig(),
            'config/observability.php' => $this->observabilityConfig(),
            'config/i18n.php' => $this->i18nConfig(),
            'config/view.php' => $this->viewConfig(),
            'config/cache.php' => $this->cacheConfig(),
            'config/mail.php' => $this->mailConfig(),
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
            ProjectPreset::Web => $this->indexPhpWeb(),
            ProjectPreset::Api => $this->indexPhpApi(),
        };
    }

    private function indexPhpMinimal(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            $basePath = dirname(__DIR__);

            // Only when unset, so an FPM pool env / systemd Environment= wins.
            if (getenv('PULSAR_BASE_PATH') === false || getenv('PULSAR_BASE_PATH') === '') {
                putenv('PULSAR_BASE_PATH=' . $basePath);
            }

            require $basePath . '/vendor/autoload.php';

            use Pulsar\Core\Kernel;
            use Pulsar\Http\Message\Response;

            $kernel = new Kernel();

            $kernel->router()->get('/', static function (): Response {
                return Response::html('<h1>Hello from Pulsar!</h1>');
            }, 'home');

            $kernel->run();
            PHP;
    }

    private function indexPhpWeb(): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            \$basePath = dirname(__DIR__);

            // Only when unset, so an FPM pool env / systemd Environment= wins.
            if (getenv('PULSAR_BASE_PATH') === false || getenv('PULSAR_BASE_PATH') === '') {
                putenv('PULSAR_BASE_PATH=' . \$basePath);
            }

            require \$basePath . '/vendor/autoload.php';

            use Pulsar\\Config\\ConfigManager;
            use Pulsar\\Core\\Kernel;
            use Pulsar\\Extensibility\\ExtensionBootstrap;
            use Pulsar\\Http\\Message\\Response;

            \$configManager = new ConfigManager(
                configPath: \$basePath . '/config',
                envFilePath: \$basePath . '/.env',
            );

            \$extensions = ExtensionBootstrap::create();

            // Discover extensions: framework bundled + project-local
            \$extensionPaths = [];
            \$fwExt = \$basePath . '/vendor/pulsar/framework/extensions';
            if (is_dir(\$fwExt)) {
                \$extensionPaths[] = \$fwExt;
            }
            \$projExt = \$basePath . '/extensions';
            if (is_dir(\$projExt)) {
                \$extensionPaths[] = \$projExt;
            }

            // Apply the extension enable posture from config/app.php: the
            // optional exclusive allowlist (extensions.enabled) and the additive
            // product opt-in (extensions.enabled_products). With neither set,
            // bundled products stay off by default while infrastructure and your
            // own extensions load.
            \$appCfg = \$basePath . '/config/app.php';
            if (is_file(\$appCfg)) {
                \$cfg = require \$appCfg;
                if (is_array(\$cfg) && isset(\$cfg['extensions']) && is_array(\$cfg['extensions'])) {
                    if (isset(\$cfg['extensions']['enabled']) && is_array(\$cfg['extensions']['enabled'])) {
                        \$extensions->setEnabledFilter(\$cfg['extensions']['enabled']);
                    }
                    if (isset(\$cfg['extensions']['enabled_products']) && is_array(\$cfg['extensions']['enabled_products'])) {
                        \$extensions->setEnabledProducts(\$cfg['extensions']['enabled_products']);
                    }
                }
            }

            \$extensions->loadFromPaths(\$extensionPaths);

            \$kernel = new Kernel(
                extensionBootstrap: \$extensions,
                configManager: \$configManager,
            );

            \$kernel->run();
            PHP;
    }

    private function indexPhpApi(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            $basePath = dirname(__DIR__);

            // Only when unset, so an FPM pool env / systemd Environment= wins.
            if (getenv('PULSAR_BASE_PATH') === false || getenv('PULSAR_BASE_PATH') === '') {
                putenv('PULSAR_BASE_PATH=' . $basePath);
            }

            require $basePath . '/vendor/autoload.php';

            use App\Http\Controller\HealthController;
            use Pulsar\Config\ConfigManager;
            use Pulsar\Core\Kernel;
            use Pulsar\Extensibility\ExtensionBootstrap;
            use Pulsar\Http\Message\Response;

            $configManager = new ConfigManager(
                configPath: $basePath . '/config',
                envFilePath: $basePath . '/.env',
            );

            $extensions = ExtensionBootstrap::create();

            $extensionPaths = [];
            $fwExt = $basePath . '/vendor/pulsar/framework/extensions';
            if (is_dir($fwExt)) {
                $extensionPaths[] = $fwExt;
            }
            $projExt = $basePath . '/extensions';
            if (is_dir($projExt)) {
                $extensionPaths[] = $projExt;
            }

            $appCfg = $basePath . '/config/app.php';
            if (is_file($appCfg)) {
                $cfg = require $appCfg;
                if (is_array($cfg) && isset($cfg['extensions']) && is_array($cfg['extensions'])) {
                    if (isset($cfg['extensions']['enabled']) && is_array($cfg['extensions']['enabled'])) {
                        $extensions->setEnabledFilter($cfg['extensions']['enabled']);
                    }
                    if (isset($cfg['extensions']['enabled_products']) && is_array($cfg['extensions']['enabled_products'])) {
                        $extensions->setEnabledProducts($cfg['extensions']['enabled_products']);
                    }
                }
            }

            $extensions->loadFromPaths($extensionPaths);

            $kernel = new Kernel(
                extensionBootstrap: $extensions,
                configManager: $configManager,
            );

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

                // Bundled application/product extensions (forum, cms, payments,
                // tickets, messaging, booking, analytics, feedback, devices,
                // subscriptions, releases, ai-governance, health-status) are OFF
                // by default — they declare "kind": "product" in pulsar.json.
                // Framework infrastructure and any extensions you author load
                // automatically. Turn a product on additively:
                //   'enabled_products' => ['pulsar/forum'],
                // Or take full manual control with an exclusive allowlist:
                //   'enabled' => ['pulsar/auth', 'pulsar/orm', 'pulsar/forum'],
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
            /var/
            /storage/
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
                    'handler' => 'file',
                    'lifetime' => 7200,
                    'cookie_name' => 'PULSAR_SESSION',
                    'cookie_secure' => false,
                    'cookie_httponly' => true,
                    'cookie_samesite' => 'Lax',
                ],

                'csrf' => [
                    'enabled' => true,
                    'form_field_name' => '_csrf_token',
                    'header_name' => 'X-CSRF-Token',
                ],

                'headers' => [
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'DENY',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
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
                    'handler' => 'file',
                    'lifetime' => 0,
                ],

                'csrf' => [
                    'enabled' => false,
                ],

                'headers' => [
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'DENY',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
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

            use Psr\Http\Message\ServerRequestInterface;
            use Pulsar\Http\Message\Response;

            final class HealthController
            {
                public function index(ServerRequestInterface $request): Response
                {
                    return Response::json([
                        'status' => 'healthy',
                        'timestamp' => time(),
                    ]);
                }
            }
            PHP;
    }

    private function databaseConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'default' => $_ENV['DB_CONNECTION'] ?? 'sqlite',

                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => __DIR__ . '/../var/database.sqlite',
                    ],
                ],

                'migrations' => [
                    'path' => __DIR__ . '/../database/migrations',
                    'table' => 'migrations',
                ],
            ];
            PHP;
    }

    private function observabilityConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'logging' => [
                    'default_channel' => 'file',
                    'channels' => [
                        'file' => [
                            'driver' => 'file',
                            'path' => __DIR__ . '/../var/logs/app.log',
                            'level' => $_ENV['LOG_LEVEL'] ?? 'debug',
                        ],
                    ],
                ],

                'metrics' => [
                    'enabled' => false,
                ],

                'tracing' => [
                    'enabled' => false,
                ],
            ];
            PHP;
    }

    private function i18nConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'fallback_locale' => 'en',
            ];
            PHP;
    }

    private function viewConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'paths' => [
                    __DIR__ . '/../resources/views',
                ],

                'compiled_path' => __DIR__ . '/../var/cache/views',
            ];
            PHP;
    }

    private function cacheConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'default' => 'file',

                'stores' => [
                    'file' => [
                        'driver' => 'file',
                        'path' => __DIR__ . '/../var/cache/data',
                    ],
                ],
            ];
            PHP;
    }

    private function mailConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'default' => 'log',

                'mailers' => [
                    'log' => [
                        'driver' => 'log',
                    ],
                ],

                'from' => [
                    'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@example.com',
                    'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Pulsar App',
                ],
            ];
            PHP;
    }
}
