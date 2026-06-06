<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\Command\ScaffoldTrait;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function is_int;
use function mkdir;
use function sprintf;

/**
 * Scaffold an event class.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class MakeEventCommand extends Command
{
    use ScaffoldTrait;

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:event';
        $this->description = 'Generate an event class';
        $this->addArgument('name', 'Event name (e.g., UserRegistered)', true);
        $this->addOption('module', 'Target module', 'm');
        $this->addOption('path', 'Base modules path', 'p', 'app/Modules');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->resolveModuleContext($input, $output, 'Event');

        if (is_int($context)) {
            return $context;
        }

        [$name, , $modulePath, $namespace] = $context;

        $eventDir = $modulePath . DIRECTORY_SEPARATOR . 'Event';

        if (!is_dir($eventDir)) {
            mkdir($eventDir, 0o750, true);
        }

        $filePath = $eventDir . DIRECTORY_SEPARATOR . $name . '.php';

        if (file_exists($filePath)) {
            $output->errorln(sprintf('Event "%s" already exists at %s', $name, $filePath));
            return ExitCode::Error->value;
        }

        $content = $this->generateEventContent($namespace, $name);
        file_put_contents($filePath, $content);

        $output->success(sprintf('Event created: %s', $filePath));

        return ExitCode::Success->value;
    }

    private function generateEventContent(string $namespace, string $name): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Event;

            /**
             * Event dispatched when {$name} occurs.
             */
            final readonly class {$name}
            {
                public function __construct(
                    public string \$id,
                    public \\DateTimeImmutable \$occurredAt = new \\DateTimeImmutable(),
                ) {}
            }
            PHP;
    }
}
