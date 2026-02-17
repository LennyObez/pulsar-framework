<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function array_keys;
use function file_exists;
use function getcwd;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * Open or list documentation topics.
 *
 * Usage: docs [topic]
 *   Without a topic, lists all available documentation files.
 *   With a topic, shows its path (usable with --open to launch a browser).
 */
#[Internal]
final class DocsCommand extends Command
{
    /** @var array<string, string> Topic name -> relative file path */
    private const array TOPIC_MAP = [
        'install' => 'docs/install.md',
        'architecture' => 'docs/architecture.md',
        'authentication' => 'docs/authentication.md',
        'authorization' => 'docs/authorization.md',
        'caching' => 'docs/caching.md',
        'compliance' => 'docs/compliance-matrix.md',
        'configuration' => 'docs/configuration.md',
        'database' => 'docs/database.md',
        'deployment' => 'docs/deployment.md',
        'events' => 'docs/events.md',
        'extensions' => 'docs/extensions.md',
        'forms' => 'docs/forms.md',
        'http' => 'docs/http.md',
        'integrity' => 'docs/integrity.md',
        'logging' => 'docs/logging.md',
        'mail' => 'docs/mail.md',
        'middleware' => 'docs/middleware.md',
        'migrations' => 'docs/migrations.md',
        'notification' => 'docs/notification.md',
        'observability' => 'docs/observability.md',
        'orm' => 'docs/orm.md',
        'payments' => 'docs/payments.md',
        'performance' => 'docs/performance.md',
        'runtime' => 'docs/runtime.md',
        'scheduler' => 'docs/scheduler.md',
        'templating' => 'docs/templating.md',
        'upgrade' => 'docs/upgrade.md',
        'validation' => 'docs/validation.md',
    ];

    #[Override]
    protected function configure(): void
    {
        $this->name = 'docs';
        $this->description = 'List or open documentation topics';
        $this->addArgument('topic', 'Documentation topic to view');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $topic = $input->getArgument(0);

        if (!is_string($topic) || $topic === '') {
            return $this->listTopics($output);
        }

        return $this->showTopic($topic, $output);
    }

    private function listTopics(OutputInterface $output): int
    {
        $output->writeln('Available Documentation Topics:');
        $output->newLine();

        $topics = array_keys(self::TOPIC_MAP);
        sort($topics);

        foreach ($topics as $topic) {
            $output->writeln(sprintf('  %s', $topic));
        }

        $output->newLine();
        $output->writeln('Usage: pulsar docs <topic>');

        return ExitCode::Success->value;
    }

    private function showTopic(string $topic, OutputInterface $output): int
    {
        $lower = strtolower($topic);

        if (!isset(self::TOPIC_MAP[$lower])) {
            $output->errorln(sprintf('Unknown topic: "%s"', $topic));
            $output->writeln('Run "pulsar docs" to see available topics.');
            return ExitCode::Error->value;
        }

        $relativePath = self::TOPIC_MAP[$lower];

        $cwd = getcwd();

        if ($cwd === false) {
            $output->errorln('Failed to determine current working directory.');
            return ExitCode::Error->value;
        }

        $fullPath = $cwd . DIRECTORY_SEPARATOR . $relativePath;

        if (!file_exists($fullPath)) {
            $output->errorln(sprintf('Documentation file not found: %s', $fullPath));
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Documentation: %s', strtoupper($lower)));
        $output->writeln(sprintf('File: %s', $fullPath));

        return ExitCode::Success->value;
    }
}
