<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ReplConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;

use function class_exists;
use function implode;
use function is_string;
use function microtime;
use function sprintf;

/**
 * Interactive REPL command with full framework context.
 *
 * Launches a PsySH-based shell with the application container, helper
 * functions, syntax highlighting, autocompletion, safe-mode wrappers,
 * sandbox mode, environment guards, and audit logging.
 *
 * Usage:
 *   pulsar repl                     Start the REPL
 *   pulsar repl --sandbox           Start in sandbox mode (DB writes rolled back)
 *   pulsar repl --readonly          Start in read-only mode (all writes blocked)
 *   pulsar repl --no-safe-mode      Disable safe mode wrappers
 */
#[Internal]
final class ReplCommand extends Command
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly EnvironmentMode $mode,
        private readonly EnvironmentGuard $guard,
        private readonly ReplConfig $config,
        private readonly ?ReplAuditLogger $auditLogger = null,
        private readonly ?SecretRedactor $redactor = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'repl';
        $this->description = 'Start an interactive REPL with full framework context';
        $this->addOption('sandbox', 'Run in sandbox mode (DB writes are rolled back at exit)');
        $this->addOption('readonly', 'Block all write operations');
        $this->addOption('no-safe-mode', 'Disable safe mode (allow mutations)');
        $this->addOption('i-know-what-im-doing', 'Required flag for production REPL access');
        $this->addOption('no-audit', 'Disable audit logging for this session');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check PsySH availability
        if (!class_exists('Psy\\Shell')) {
            $output->errorln('PsySH is not installed. Install it with:');
            $output->errorln('  composer require --dev psy/psysh');

            return ExitCode::Error->value;
        }

        // Environment guard check
        $forceFlag = $input->hasOption('i-know-what-im-doing');
        $guardResult = $this->guard->canStart($forceFlag);

        if (!$guardResult->allowed) {
            $output->errorln($guardResult->reason);

            return ExitCode::Error->value;
        }

        // Determine modes
        $sandboxMode = $input->hasOption('sandbox');
        $readonlyMode = $input->hasOption('readonly');
        $safeMode = ($this->config->safeMode && !$input->hasOption('no-safe-mode'))
            || $readonlyMode;

        // readonly and sandbox are mutually exclusive
        if ($readonlyMode && $sandboxMode) {
            $output->errorln('Cannot use --readonly and --sandbox together. Choose one.');

            return ExitCode::Error->value;
        }

        // Apply safe mode / readonly wrappers
        if ($safeMode && !$sandboxMode) {
            $safeModeProvider = new SafeModeProvider($this->container);
            $safeModeProvider->apply();
        }

        // Set up sandbox connection
        $sandboxConnection = null;

        if ($sandboxMode && $this->container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $this->container->get(ConnectionInterface::class);
            $sandboxConnection = new SandboxConnection($connection);
            $sandboxConnection->begin();
            $this->container->instance(ConnectionInterface::class, $sandboxConnection);
        }

        // Audit logging
        $auditEnabled = $this->config->audit && !$input->hasOption('no-audit');
        $actor = $this->resolveActor();

        if ($this->mode === EnvironmentMode::Production && !$auditEnabled) {
            $auditEnabled = true;
            $output->warning('Audit logging cannot be disabled in production. --no-audit ignored.');
        }

        if ($guardResult->isProductionOverride && $auditEnabled && $this->auditLogger !== null) {
            $this->auditLogger->logProductionOverride($actor);
        }

        if ($auditEnabled && $this->auditLogger !== null) {
            $this->auditLogger->logSessionStart($actor, $this->mode, $safeMode);
        }

        $startTime = microtime(true);

        // Display banner
        $modeLabels = [];

        if ($safeMode) {
            $modeLabels[] = 'safe mode';
        }

        if ($sandboxMode) {
            $modeLabels[] = 'sandbox';
        }

        if ($readonlyMode) {
            $modeLabels[] = 'read-only';
        }

        if ($modeLabels === []) {
            $modeLabels[] = 'unrestricted';
        }

        $output->info(sprintf(
            'Pulsar REPL (%s, %s)',
            $this->mode->value,
            implode(' + ', $modeLabels),
        ));

        if ($safeMode) {
            $output->info('Safe mode: database writes, queue dispatch, storage writes, and cache writes are blocked.');
        }

        if ($sandboxMode) {
            $output->info('Sandbox mode: all database changes will be rolled back when you exit.');
        }

        $output->writeln('Type "exit" or press Ctrl+D to quit.');
        $output->newLine();

        // Build helpers and scope
        $printer = new ResultPrinter($this->redactor);
        $helpers = new ReplHelpers($this->container, $printer, $this->redactor);

        $exposedContainer = $safeMode ? new ReadOnlyContainer($this->container) : $this->container;

        $scopeVars = [
            'container' => $exposedContainer,
            'helpers' => $helpers,
        ];

        if ($this->redactor !== null) {
            $scopeVars['redactor'] = $this->redactor;
        }

        // Announce available helpers
        $output->writeln('Available: $container, $helpers->dump(), $helpers->route(), $helpers->sql(), $helpers->doc(), $helpers->bench(), $helpers->profile()');
        $output->newLine();

        // Run PsySH
        try {
            $exitCode = $this->runPsyShell($scopeVars);
        } finally {
            // Roll back sandbox transaction
            $sandboxConnection?->rollback();

            // Log session end
            if ($auditEnabled && $this->auditLogger !== null) {
                $duration = microtime(true) - $startTime;
                $this->auditLogger->logSessionEnd($actor, null, $duration);
            }
        }

        if ($sandboxMode) {
            $output->info('Sandbox: all database changes have been rolled back.');
        }

        return $exitCode;
    }

    /**
     * Configure and run the PsySH shell.
     *
     * @param array<string, mixed> $scopeVars
     */
    private function runPsyShell(array $scopeVars): int
    {
        /** @var class-string<\Psy\Configuration> $configClass */
        $configClass = 'Psy\\Configuration';
        /** @var class-string<\Psy\Shell> $shellClass */
        $shellClass = 'Psy\\Shell';

        $psyConfig = new $configClass([
            'updateCheck' => 'never',
            'usePcntl' => false,
            'historyFile' => $this->config->historyFile,
        ]);

        $shell = new $shellClass($psyConfig);
        $shell->setScopeVariables($scopeVars);

        if ($this->config->startupCommands !== []) {
            $shell->addInput(implode("\n", $this->config->startupCommands));
        }

        /** @var int */
        return $shell->run();
    }

    /**
     * Resolve the current actor identity for audit logging.
     */
    private function resolveActor(): string
    {
        $user = $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? null;

        return is_string($user) ? $user : 'unknown';
    }
}
