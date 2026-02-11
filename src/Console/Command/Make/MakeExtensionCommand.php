<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use JsonException;
use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Version;

use function is_string;
use function sprintf;

/**
 * Scaffold a new extension structure.
 */
final class MakeExtensionCommand extends Command
{
    use ScaffoldTrait;

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:extension';
        $this->description = 'Generate a new extension structure';
        $this->addArgument('name', 'Extension name (e.g., my-extension)', true);
        $this->addOption('vendor', 'Vendor name', null, 'acme');
        $this->addOption('path', 'Base path for extensions', 'p', 'extensions');
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $vendor = $input->getOption('vendor', 'acme');
        $basePath = $input->getOption('path', 'extensions');

        if (!is_string($name) || $name === '') {
            $output->errorln('Extension name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($vendor)) {
            $vendor = 'acme';
        }

        if (!is_string($basePath)) {
            $basePath = 'extensions';
        }

        // Normalize names
        $dirName = $this->toKebabCase($name);
        $className = $this->toPascalCase($name);
        $fullName = $vendor . '/' . $dirName;
        $namespace = $this->toPascalCase($vendor) . '\\' . $className;

        $cwd = getcwd();
        if ($cwd === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $extensionPath = $cwd . DIRECTORY_SEPARATOR . $basePath . DIRECTORY_SEPARATOR . $dirName;

        if (is_dir($extensionPath)) {
            $output->errorln(sprintf('Extension "%s" already exists at %s', $fullName, $extensionPath));
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Scaffolding extension: %s', $fullName));
        $output->newLine();

        // Create directory structure
        if (!$this->createDirectories($extensionPath, $dirName, ['', 'src', 'src/Controller'], $output)) {
            return ExitCode::Error->value;
        }

        // Create files
        $this->writeFiles($extensionPath, [
            'pulsar.json' => $this->getManifestContent($fullName, $namespace, $className),
            'composer.json' => $this->getComposerContent($fullName, $namespace),
            'src/' . $className . 'Extension.php' => $this->getExtensionContent($namespace, $className),
            'src/' . $className . 'ServiceProvider.php' => $this->getServiceProviderContent($namespace, $className),
            'src/' . $className . 'Service.php' => $this->getServiceContent($namespace, $className),
            'src/Controller/' . $className . 'Controller.php' => $this->getControllerContent($namespace, $className),
        ], $output);

        $output->newLine();
        $output->success(sprintf('Extension "%s" scaffolded successfully!', $fullName));
        $output->newLine();
        $output->writeln('To use this extension:');
        $output->writeln('  1. Add the extension path to your extension loader');
        $output->writeln('  2. Run composer dump-autoload');

        return ExitCode::Success->value;
    }

    /**
     * @throws JsonException
     */
    private function getManifestContent(string $fullName, string $namespace, string $className): string
    {
        $manifest = [
            'name' => $fullName,
            'version' => '0.1.0',
            'description' => 'A Pulsar Framework extension',
            'extension_class' => $namespace . '\\' . $className . 'Extension',
            'pulsar' => [
                'min_version' => Version::short(),
            ],
            'provides' => [
                'services' => [$className . 'Service'],
                'routes' => true,
            ],
        ];

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @throws JsonException
     */
    private function getComposerContent(string $fullName, string $namespace): string
    {
        $composer = [
            'name' => $fullName,
            'description' => 'A Pulsar Framework extension',
            'type' => 'pulsar-extension',
            'require' => [
                'php' => '^8.5',
            ],
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ];

        return json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function getExtensionContent(string $namespace, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace;

            use Pulsar\\Container\\ContainerInterface;
            use Pulsar\\Extensibility\\ExtensionInterface;
            use Pulsar\\Extensibility\\ServiceProviderInterface;
            use Pulsar\\Routing\\RouterInterface;
            use $namespace\\Controller\\{$className}Controller;

            final class {$className}Extension implements ExtensionInterface
            {
                public function name(): string
                {
                    return '$namespace';
                }

                public function register(ContainerInterface \$container): void
                {
                    // Register any additional services here
                }

                public function boot(ContainerInterface \$container, RouterInterface \$router): void
                {
                    // Register routes
                    \$router->get('/{$this->toKebabCase($className)}', [{$className}Controller::class, 'index'], '{$this->toKebabCase($className)}.index');
                }

                /**
                 * @return list<class-string<ServiceProviderInterface>>
                 */
                public function providers(): array
                {
                    return [
                        {$className}ServiceProvider::class,
                    ];
                }
            }
            PHP;
    }

    private function getServiceProviderContent(string $namespace, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace;

            use Pulsar\\Container\\ContainerInterface;
            use Pulsar\\Extensibility\\ServiceProviderInterface;

            final class {$className}ServiceProvider implements ServiceProviderInterface
            {
                public function register(ContainerInterface \$container): void
                {
                    \$container->bind({$className}Service::class, {$className}Service::class);
                }

                public function provides(): array
                {
                    return [
                        {$className}Service::class,
                    ];
                }
            }
            PHP;
    }

    private function getServiceContent(string $namespace, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace;

            final class {$className}Service
            {
                public function getMessage(): string
                {
                    return 'Hello from $className!';
                }
            }
            PHP;
    }

    private function getControllerContent(string $namespace, string $className): string
    {
        $serviceName = $this->toCamelCase($className) . 'Service';
        $messageCall = '$this->' . $serviceName . '->getMessage()';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Controller;

            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\Response;
            use $namespace\\{$className}Service;

            final class {$className}Controller
            {
                public function __construct(
                    private readonly {$className}Service \$$serviceName,
                ) {}

                public function index(Request \$request): Response
                {
                    return Response::json([
                        'message' => $messageCall,
                    ]);
                }
            }
            PHP;
    }
}
