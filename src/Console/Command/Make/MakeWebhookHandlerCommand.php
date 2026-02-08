<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function is_dir;
use function is_string;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\Make\Template\WebhookTemplates;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Scaffold a webhook handler with verification, deduplication, and controller.
 */
final class MakeWebhookHandlerCommand extends Command
{
    use ScaffoldTrait;

    private readonly WebhookTemplates $templates;

    public function __construct()
    {
        $this->templates = new WebhookTemplates();
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:webhook-handler';
        $this->description = 'Generate a webhook handler with verification and deduplication';
        $this->addArgument('name', 'Webhook handler name (e.g., Stripe, GitHub)', true);
        $this->addOption('module', 'Module name', 'm');
        $this->addOption('path', 'Base path for modules', 'p', 'app/Modules');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument(0);
        $module = $input->getOption('module');
        $basePath = $input->getOption('path', 'app/Modules');

        if (!is_string($name) || $name === '') {
            $output->errorln('Webhook handler name is required.');
            return ExitCode::Invalid->value;
        }

        if (!is_string($module) || $module === '') {
            $output->errorln('Module name is required (--module).');
            return ExitCode::Invalid->value;
        }

        if (!is_string($basePath)) {
            $basePath = 'app/Modules';
        }

        $name = $this->toPascalCase($name);
        $module = $this->toPascalCase($module);

        $resolved = $this->resolveBasePath($basePath, 'app/Modules');
        if ($resolved === false) {
            $output->errorln('Failed to get current working directory.');
            return ExitCode::Error->value;
        }

        $modulePath = $resolved . DIRECTORY_SEPARATOR . $module;

        if (!is_dir($modulePath)) {
            $output->errorln(sprintf('Module "%s" does not exist at %s', $module, $modulePath));
            return ExitCode::Error->value;
        }

        $namespace = 'App\\Modules\\' . $module;

        $output->writeln(sprintf('Creating webhook handler: %s in %s', $name, $module));
        $output->newLine();

        // Create directory structure
        $directories = [];
        foreach (['Contracts', 'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure', 'Controller', 'Config', 'Domain'] as $dir) {
            $fullDir = $modulePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullDir)) {
                $directories[] = $dir;
            }
        }

        if ($directories !== []) {
            if (!$this->createDirectories($modulePath, $module, $directories, $output)) {
                return ExitCode::Error->value;
            }
        }

        // Create files
        $this->writeFiles($modulePath, [
            'Contracts' . DIRECTORY_SEPARATOR . $name . 'WebhookHandlerInterface.php' => $this->templates->handlerInterface($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'WebhookHandler.php' => $this->templates->handler($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'HmacVerifier.php' => $this->templates->hmacVerifier($name, $namespace),
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'InMemory' . $name . 'EventLog.php' => $this->templates->eventLog($name, $namespace),
            'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookController.php' => $this->templates->controller($name, $namespace),
            'Config' . DIRECTORY_SEPARATOR . $name . 'WebhookConfig.php' => $this->templates->config($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'WebhookEvent.php' => $this->templates->event($name, $namespace),
            'Domain' . DIRECTORY_SEPARATOR . $name . 'WebhookEventType.php' => $this->templates->eventType($name, $namespace),
        ], $output);

        // Create tests
        $cwd = getcwd();
        if ($cwd !== false) {
            $testBase = $cwd . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit'
                . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . $module;

            $testDirs = [
                'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure',
                'Controller',
            ];
            foreach ($testDirs as $dir) {
                $fullDir = $testBase . DIRECTORY_SEPARATOR . $dir;
                if (!is_dir($fullDir)) {
                    mkdir($fullDir, 0o755, true);
                }
            }

            $this->writeFiles($testBase, [
                'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . $name . 'WebhookHandlerTest.php' => $this->templates->handlerTest($name, $module, $namespace),
                'Controller' . DIRECTORY_SEPARATOR . $name . 'WebhookControllerTest.php' => $this->templates->controllerTest($name, $module, $namespace),
            ], $output);
        }

        $output->newLine();
        $output->success(sprintf('Webhook handler "%s" created in %s/', $name, $module));

        return ExitCode::Success->value;
    }
}
