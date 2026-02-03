<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function is_string;

use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Scaffold a new module structure.
 */
final class ScaffoldModuleCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('scaffold:module')
            ->setDescription('Generate a new module structure')
            ->addArgument('name', 'Module name (e.g., User, Blog, Admin)', true)
            ->addOption('path', 'Base path for modules', 'p', 'app/Modules');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $basePath = $input->getOption('path', 'app/Modules');

        if (!is_string($name) || $name === '') {
            $output->errorln('Module name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        // Normalize name to PascalCase
        $name = $this->toPascalCase($name);

        $cwd = getcwd();
        if ($cwd === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $modulePath = $cwd . DIRECTORY_SEPARATOR . $basePath . DIRECTORY_SEPARATOR . $name;

        if (is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" already exists at %s', $name, $modulePath));
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Scaffolding module: %s', $name));
        $output->newLine();

        // Create directory structure
        $directories = [
            '',
            'Controllers',
            'Services',
            'Models',
            'Middleware',
            'Views',
        ];

        foreach ($directories as $dir) {
            $path = $modulePath . ($dir !== '' ? DIRECTORY_SEPARATOR . $dir : '');
            if (!mkdir($path, 0o755, true)) {
                $output->errorln('Failed to create directory: ' . $path);
                return ExitCode::Error->value;
            }
            $output->writeln(sprintf('  Created %s/', $dir !== '' ? $dir : $name));
        }

        // Create files
        $namespace = 'App\\Modules\\' . $name;

        $files = [
            'ModuleServiceProvider.php' => $this->getServiceProviderContent($name, $namespace),
            'Controllers/' . $name . 'Controller.php' => $this->getControllerContent($name, $namespace),
            'Services/' . $name . 'Service.php' => $this->getServiceContent($name, $namespace),
            'routes.php' => $this->getRoutesContent($name, $namespace),
        ];

        foreach ($files as $file => $content) {
            $filePath = $modulePath . DIRECTORY_SEPARATOR . $file;
            file_put_contents($filePath, $content);
            $output->writeln(sprintf('  Created %s', $file));
        }

        $output->newLine();
        $output->success(sprintf('Module "%s" scaffolded successfully!', $name));

        return ExitCode::Success->value;
    }

    private function toPascalCase(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    private function getServiceProviderContent(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Pulsar\Container\ContainerInterface;
            use Pulsar\Extensibility\ServiceProviderInterface;
            use {$namespace}\\Services\\{$name}Service;

            final class ModuleServiceProvider implements ServiceProviderInterface
            {
                public function register(ContainerInterface \$container): void
                {
                    \$container->bind({$name}Service::class, {$name}Service::class);
                }

                public function provides(): array
                {
                    return [
                        {$name}Service::class,
                    ];
                }
            }
            PHP;
    }

    private function getControllerContent(string $name, string $namespace): string
    {
        $lcName = lcfirst($name);
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Controllers;

            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\Response;
            use {$namespace}\\Services\\{$name}Service;

            final class {$name}Controller
            {
                public function __construct(
                    private readonly {$name}Service \${$lcName}Service,
                ) {}

                public function index(Request \$request): Response
                {
                    return Response::json([
                        'module' => '{$name}',
                        'message' => 'Hello from {$name} module!',
                    ]);
                }

                public function show(Request \$request, array \$params): Response
                {
                    \$id = \$params['id'] ?? null;

                    return Response::json([
                        'module' => '{$name}',
                        'id' => \$id,
                    ]);
                }
            }
            PHP;
    }

    private function getServiceContent(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Services;

            final class {$name}Service
            {
                // Add your service methods here
            }
            PHP;
    }

    private function getRoutesContent(string $name, string $namespace): string
    {
        $lcName = lcfirst($name);
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Pulsar\\Routing\\Router;
            use {$namespace}\\Controllers\\{$name}Controller;

            return function (Router \$router): void {
                \$router->get('/{$lcName}', [{$name}Controller::class, 'index'], '{$lcName}.index');
                \$router->get('/{$lcName}/{id}', [{$name}Controller::class, 'show'], '{$lcName}.show');
            };
            PHP;
    }
}
