<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use function is_dir;
use function is_int;

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
        $context = $this->resolveModuleContext($input, $output, 'Webhook handler');
        if (is_int($context)) {
            return $context;
        }

        [$name, $module, $modulePath, $namespace] = $context;

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
        $testBase = $this->resolveTestBasePath($module, [
            'Internal' . DIRECTORY_SEPARATOR . 'Infrastructure',
            'Controller',
        ]);
        if ($testBase !== false) {
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
