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
            // `security` is one of ConfigManager::REQUIRED_CONFIGS alongside
            // `app` and `observability`: a project without it cannot complete
            // ConfigManager::load() and therefore cannot boot. It belongs here,
            // not in a per-preset list — the Minimal preset used by `init` once
            // omitted it and produced projects that threw MissingConfigException
            // on their first command. Which *posture* it ships with still varies
            // by preset; whether it ships does not.
            'config/security.php' => $this->securityConfig($preset),
            'config/extensions.php' => $this->extensionsConfig(),
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
            // `.pulse.php`, not `.php`: TemplateCompiler resolves the template
            // name `welcome` to `welcome.pulse.php` and only to that. Written
            // as plain `.php` the file was generated into every web project and
            // could never be rendered by the engine the same project configures.
            'resources/views/welcome.pulse.php' => $this->welcomeView($appName),
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
        ];
    }

    /**
     * The security posture a preset ships with.
     *
     * Minimal takes the browser-facing posture rather than the API one: a
     * minimal project serves HTML from `public/index.php`, so it is exposed to
     * exactly the cross-site request forgery the API posture switches off. A
     * project that later becomes an API turns CSRF off deliberately; one that
     * silently started without it never makes that decision at all.
     */
    private function securityConfig(ProjectPreset $preset): string
    {
        return match ($preset) {
            ProjectPreset::Api => $this->securityConfigApi(),
            ProjectPreset::Minimal, ProjectPreset::Web => $this->securityConfigWeb(),
        };
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

            // Renders resources/views/welcome.pulse.php through the engine
            // config/view.php configures. Without a route here the generated
            // project answered its own "open http://localhost:8000" with a 404
            // and the welcome view it shipped was never reachable.
            \$kernel->router()->get('/', static function (): Response {
                return Response::view('welcome');
            }, 'home');

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

    private function extensionsConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            /**
             * Extension trust tiers.
             *
             * Pulsar's ExtensionSandbox reads this file and fails CLOSED: an
             * extension named nowhere in it runs at the Community cap whatever
             * tier its own pulsar.json asks for, and the first extension that
             * then needs a core capability aborts the boot. So this file is not
             * optional for a project that loads the framework's bundled
             * extensions — which `vendor/bin/pulsar` does, and so does the Web
             * and API `public/index.php`.
             *
             * The bundled first-party tiers are read from the framework package
             * instead of copied here, so they cannot drift behind a framework
             * upgrade that adds an extension. They are already least-privilege:
             * infrastructure and security extensions at `core`, bundled products
             * at `verified` — able to register and decorate their own services,
             * unable to override a core binding, read the master key, or exec.
             *
             * Trust for anything YOU install is your decision and belongs below,
             * one entry at a time:
             *
             *   'acme/reporting' => ['tier' => 'verified'],
             *   'acme/legacy' => ['tier' => 'community', 'additional_capabilities' => ['DatabaseRaw']],
             *
             * An extension you say nothing about stays capped at Community.
             */

            /*
             * Where the framework is, asked rather than assumed.
             *
             * This line used to read `__DIR__ . '/../vendor/pulsar/framework/config/extensions.php'`
             * and require it unconditionally. That path is a guess about somebody else's
             * install layout, and it is wrong in three ordinary situations: `init` writes
             * this file BEFORE `composer install` has created `vendor/` at all (the order
             * docs/install.md gives), `composer config vendor-dir` renames the directory,
             * and a monorepo or a path repository puts the package somewhere else entirely.
             * In each of them the require failed and took the whole boot with it — every
             * command in the new project exited 1 before running.
             *
             * Whatever is reading this file has already loaded the framework, so the
             * framework's own class file is the one fact available here that is true on
             * every layout. `Kernel` lives at `src/Core/Kernel.php`, three levels below the
             * package root.
             */
            $kernelFile = (new ReflectionClass(Pulsar\Core\Kernel::class))->getFileName();
            $bundledFile = $kernelFile === false
                ? null
                : dirname($kernelFile, 3) . '/config/extensions.php';

            if ($bundledFile === null || !is_file($bundledFile)) {
                throw new RuntimeException(
                    'Could not read the framework\'s bundled extension trust tiers'
                    . ($bundledFile === null ? '' : " at {$bundledFile}")
                    . '. Without them every bundled extension is capped at Community and the '
                    . 'boot stops at the first one needing a core capability. Reinstall '
                    . 'pulsar/framework, or list the tiers you trust in this file yourself.',
                );
            }

            $bundled = require $bundledFile;

            return [
                'trusted_extensions' => [
                    ...$bundled['trusted_extensions'],
                ],
            ];
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

                    // The level belongs to `logging`, not to a channel:
                    // ObservabilityConfig reads logging.level and the channels
                    // carry driver/path/stream only. LOG_LEVEL overrides it.
                    'level' => 'debug',

                    'channels' => [
                        'file' => [
                            'driver' => 'file',
                            'path' => __DIR__ . '/../var/logs/app.log',
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

                // Plural, and a list: I18nConfig reads `fallback_locales`.
                'fallback_locales' => ['en'],
            ];
            PHP;
    }

    private function viewConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                // `template_paths` and `cache_path` are the keys ViewConfig
                // reads. Search paths are absolute so a template resolves the
                // same under the built-in server, FPM and the CLI, none of
                // which agree on the working directory.
                'template_paths' => [
                    dirname(__DIR__) . '/resources/views',
                ],

                // Relative on purpose: resolved against the project root and
                // refused if it lands inside public/.
                'cache_path' => 'var/cache/views',
            ];
            PHP;
    }

    private function cacheConfig(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                // Off by default. Switching it on binds the PSR-6/PSR-16 pools
                // and the tagged cache that duplicate detection and reputation
                // cooldown need; leaving it off keeps a new project's behaviour
                // free of a cache tier it has not thought about yet.
                'enabled' => false,

                'default_pool' => 'default',
                'path' => 'var/cache',

                'pools' => [
                    'default' => [
                        'driver' => 'filesystem',
                        'serializer' => 'json',
                        'default_ttl_seconds' => null,
                        'critical' => false,
                        'encrypted' => false,
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
                // Sending is off until you turn it on, and the driver writes to
                // the log rather than the network until you pick a real one, so
                // a project under development cannot mail a live customer by
                // accident. MAIL_ENABLED / MAIL_DRIVER override both.
                'enabled' => false,
                'default_driver' => 'log',

                // MAIL_FROM_ADDRESS and MAIL_FROM_NAME override these.
                'default_from_address' => 'noreply@example.com',
                'default_from_name' => 'Pulsar App',

                'driver_options' => [
                    'smtp' => [
                        'host' => 'localhost',
                        'port' => 587,
                        'encryption' => 'tls',
                        'timeout' => 30,
                    ],
                ],
            ];
            PHP;
    }
}
