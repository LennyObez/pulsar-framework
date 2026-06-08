<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\ModuleTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function is_dir;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Scaffold a new module with Contracts/Internal separation, config DTO, and tests.
 */
final class MakeModuleCommand extends Command
{
    use ScaffoldTrait;

    private readonly ModuleTemplates $templates;

    public function __construct()
    {
        $this->templates = new ModuleTemplates();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:module';
        $this->description = 'Generate a new module with Contracts/Internal separation';
        $this->addArgument('name', 'Module name (e.g., User, Blog, Admin)', true);
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
        $this->addOption('with-config', 'Include config DTO');
        $this->addOption('with-tests', 'Include test stubs');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $basePath = $input->getStringOption('path', 'app/Modules');

        if (!is_string($name) || $name === '') {
            $output->errorln('Module name is required.');
            return ExitCode::Invalid->value;
        }

        $name = $this->toPascalCase($name);
        $withConfig = $input->hasOption('with-config');
        $withTests = $input->hasOption('with-tests');

        $resolved = $this->resolveBasePathOrFail($basePath, 'app/Modules', $output);
        if (is_int($resolved)) {
            return $resolved;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $name;

        if (is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" already exists at %s', $name, $modulePath));
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Scaffolding module: %s', $name));
        $output->newLine();

        $namespace = 'App\\Modules\\' . $name;

        // Create directory structure
        $directories = [
            '',
            'Contracts',
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure',
            'Controller',
            'Middleware',
            'Models',
            'Views',
        ];

        if ($withConfig) {
            $directories[] = 'Config';
        }

        if (!$this->createDirectories($modulePath, $name, $directories, $output)) {
            return ExitCode::Error->value;
        }

        // Create files
        $files = [
            'Contracts' . DIRECTORY_SEPARATOR . $name . 'ServiceInterface.php' => $this->templates->serviceInterface($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'Service.php' => $this->templates->serviceImplementation($name, $namespace),
            'Controller' . DIRECTORY_SEPARATOR . $name . 'Controller.php' => $this->templates->controller($name, $namespace),
            'ModuleServiceProvider.php' => $this->templates->serviceProvider($name, $namespace),
            'routes.php' => $this->templates->routes($name, $namespace),
            'README.md' => $this->templates->readme($name),
        ];

        if ($withConfig) {
            $files['Config' . DIRECTORY_SEPARATOR . $name . 'Config.php'] = $this->templates->config($name, $namespace);
        }

        $this->writeFiles($modulePath, $files, $output);

        // Create tests
        if ($withTests) {
            $cwd = getcwd();
            if ($cwd !== false) {
                $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                    . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $name;

                $testDirs = [
                    '',
                    'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure',
                    'Controller',
                ];

                if ($this->createDirectories($testBase, $name . ' Tests', $testDirs, $output)) {
                    $this->writeFiles($testBase, [
                        'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'ServiceTest.php' => $this->templates->serviceTest($name, $namespace),
                        'Controller' . DIRECTORY_SEPARATOR . $name . 'ControllerTest.php' => $this->templates->controllerTest($name, $namespace),
                    ], $output);
                }
            }
        }

        $output->newLine();
        $output->success(sprintf('Module "%s" scaffolded successfully!', $name));

        return ExitCode::Success->value;
    }
}
